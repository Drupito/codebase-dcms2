<?php

declare(strict_types=1);

namespace Drupal\trash\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Entity\TranslatableRevisionableInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\TranslationStatusInterface;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;
use Drupal\trash\TrashStorageTrait;
use Drupal\workspaces\WorkspaceInformationInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Entity hook implementations for Trash.
 */
class TrashEntityHooks {

  use RedirectDestinationTrait;
  use StringTranslationTrait;

  /**
   * Langcodes of translations being soft-deleted, keyed by the entity object.
   *
   * Stashed in entityPresave() and consumed in entityUpdate(), so the post
   * trash delete events fire only after the save has been persisted. Keyed by
   * the entity instance (the same object flows through both hooks) so a save
   * that throws before entityUpdate cannot leak its entry into a later save:
   * the object is discarded and the entry goes with it.
   *
   * @var \WeakMap<object, string[]>
   */
  private \WeakMap $pendingTranslationTrash;

  public function __construct(
    protected TrashManagerInterface $trashManager,
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
    protected ?WorkspaceInformationInterface $workspaceInformation = NULL,
  ) {
    $this->pendingTranslationTrash = new \WeakMap();
  }

  /**
   * Implements hook_entity_query_alter().
   */
  #[Hook('entity_query_alter')]
  public function entityQueryAlter(QueryInterface $query): void {
    if (!$this->trashManager->shouldAlterQueries() || !$this->trashManager->isEntityTypeEnabled($query->getEntityTypeId())) {
      return;
    }

    $reflected_condition = new \ReflectionProperty($query::class, 'condition');
    $condition_group = $reflected_condition->getValue($query);
    assert($condition_group instanceof ConditionInterface);

    // Skip altering queries with an explicit filter on the 'deleted' field, so
    // you can still list deleted content, if needed.
    $deleted_key = 'deleted';
    if ($this->hasDeletedCondition($condition_group)) {
      return;
    }

    if (!$query->getMetaData('trash')) {
      $query->addMetaData('trash', $this->trashManager->getTrashContext());
    }

    // Skip altering queries if the context has been manually set to ignore.
    if ($query->getMetaData('trash') === 'ignore') {
      return;
    }

    // If the entity query conjunction is 'OR', we need to wrap the original
    // condition group as the first condition of a new AND condition group,
    // otherwise the query would always return all non-deleted entities.
    $reflected_conjunction = new \ReflectionProperty($query::class, 'conjunction');
    if ($reflected_conjunction->getValue($query) === 'OR') {
      $reflected_conjunction->setValue($query, 'AND');

      $new_condition_group = $query->andConditionGroup()
        ->condition($condition_group);
      $reflected_condition->setValue($query, $new_condition_group);
    }

    if ($query->getMetaData('trash') === 'inactive') {
      $query->exists($deleted_key);
    }
    else {
      $query->notExists($deleted_key);
    }
  }

  /**
   * Checks whether a condition group already filters on the 'deleted' field.
   *
   * Recurses into nested AND/OR condition groups (created via
   * andConditionGroup()/orConditionGroup()), and matches both the plain
   * 'deleted' field and property paths like 'deleted.value'.
   */
  protected function hasDeletedCondition(ConditionInterface $condition_group): bool {
    foreach ($condition_group->conditions() as $condition) {
      if ($condition['field'] instanceof ConditionInterface) {
        if ($this->hasDeletedCondition($condition['field'])) {
          return TRUE;
        }
        continue;
      }
      if ($condition['field'] === 'deleted' || str_starts_with((string) $condition['field'], 'deleted.')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Implements hook_query_alter().
   *
   * Filters out deleted entities from entity load queries by altering
   * queries tagged with {entity_type_id}_load_multiple.
   */
  #[Hook('query_alter')]
  public function queryAlter(AlterableInterface $query): void {
    assert($query instanceof SelectInterface);

    // Only filter in 'active' context.
    if ($this->trashManager->getTrashContext() !== 'active') {
      return;
    }

    // Find which entity type this query is for by checking tags.
    $entity_type_id = NULL;
    foreach ($this->trashManager->getEnabledEntityTypes() as $type_id) {
      if ($query->hasTag($type_id . '_load_multiple')) {
        $entity_type_id = $type_id;
        break;
      }
    }

    if ($entity_type_id === NULL) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    assert($storage instanceof SqlEntityStorageInterface);

    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $table_mapping */
    $table_mapping = $storage->getTableMapping();
    $deleted_columns = $table_mapping->getColumnNames('deleted');
    if (!$deleted_columns) {
      return;
    }
    $deleted_column = $deleted_columns['value'];

    $entity_type = $storage->getEntityType();
    $id_key = $entity_type->getKey('id');
    $revision_key = $entity_type->getKey('revision');

    // Handle workspace-specific revision loading. Skip this alteration if
    // specific revisions are being loaded, as the original join will have
    // a :revisionIds[] argument that we should not interfere with.
    // @see \Drupal\Core\Entity\Sql\SqlContentEntityStorage::buildQuery()
    $tables =& $query->getTables();
    $loading_specific_revisions = isset($tables['revision']['arguments'][':revisionIds[]']);
    if (
      !$loading_specific_revisions
      && $this->workspaceInformation?->isEntityTypeSupported($entity_type)
      && ($active_workspace = $this->workspaceManager?->getActiveWorkspace())
    ) {
      // Join the workspace_association table so we can select possible
      // workspace-specific revisions.
      $wa_join = $query->leftJoin('workspace_association', NULL, "[%alias].[target_entity_type_id] = :entity_type_id AND [%alias].[target_entity_id] = [base].[$id_key] AND [%alias].[workspace] = :active_workspace_id", [
        ':entity_type_id' => $entity_type_id,
        ':active_workspace_id' => $active_workspace->id(),
      ]);

      // Joins must be in order. Tables mentioned in the ON clause of a JOIN
      // must appear prior to that JOIN. So we must ensure that the new
      // 'workspace_association' table appears prior to the 'revision' one.
      if (isset($tables['revision'])) {
        $revision = $tables['revision'];
        unset($tables['revision']);
        $tables['revision'] = $revision;

        $tables['revision']['condition'] = "[revision].[$revision_key] = COALESCE([$wa_join].[target_entity_revision_id], [base].[$revision_key])";
      }
    }

    // Ensure that entity_load excludes deleted entities.
    $revision_data_table = $table_mapping->getRevisionDataTable();
    $revision_table = $table_mapping->getRevisionTable();
    $data_table = $table_mapping->getDataTable();

    if ($revision_data_table) {
      // Use the alias join() actually assigns (via the '%alias' placeholder)
      // rather than assuming the requested 'revision_data' was free, so a
      // collision with another module's join on the same alias can't leave
      // this condition pointing at the wrong table.
      $revision_data_alias = $query->join($revision_data_table, 'revision_data', "[%alias].[$revision_key] = [revision].[$revision_key]");
      $query->condition("$revision_data_alias.$deleted_column", NULL, 'IS NULL');
    }
    elseif ($revision_table) {
      $query->condition("revision.$deleted_column", NULL, 'IS NULL');
    }
    elseif ($data_table) {
      $data_alias = $query->join($data_table, 'data', "[%alias].[$id_key] = [base].[$id_key]");
      $query->condition("$data_alias.$deleted_column", NULL, 'IS NULL');
    }
    else {
      $query->condition("base.$deleted_column", NULL, 'IS NULL');
    }
  }

  /**
   * Implements hook_entity_operation_alter().
   */
  #[Hook('entity_operation_alter')]
  public function entityOperationAlter(array &$operations, EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): void {
    // Skip access checks for non-deleted entities.
    if (!Trash::entityIsDeleted($entity)) {
      return;
    }

    // Remove all other operations for deleted entities.
    $operations = [];

    // When the restore/purge routes have a {language} slug, pin it to this
    // row's translation, so the scope of the operation does not depend on the
    // negotiated content language. Both link templates gain the slug together,
    // so checking 'restore' covers both.
    $entity_type = $entity->getEntityType();
    $pin_language = $entity instanceof TranslatableInterface
      && $entity_type->hasLinkTemplate('restore')
      && str_contains($entity_type->getLinkTemplate('restore'), '{language}');

    if ($entity->access('restore')) {
      $url = $entity->toUrl('restore', [
        'language' => $this->languageManager->getCurrentLanguage(),
        'attributes' => [
          'aria-label' => $this->t('Restore @label', [
            '@label' => $entity->label() ?? $entity->id(),
          ]),
        ],
      ]);
      if ($pin_language) {
        $url->setRouteParameter('language', $entity->language()->getId());
      }

      $operations['restore'] = [
        'title' => $this->t('Restore'),
        'url' => $url,
        'weight' => 0,
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 880,
          ]),
        ],
      ];
    }
    if ($entity->access('purge')) {
      $url = $entity->toUrl('purge', [
        'language' => $this->languageManager->getCurrentLanguage(),
        'attributes' => [
          'aria-label' => $this->t('Purge @label', [
            '@label' => $entity->label() ?? $entity->id(),
          ]),
        ],
      ]);
      if ($pin_language) {
        $url->setRouteParameter('language', $entity->language()->getId());
      }

      $operations['purge'] = [
        'title' => $this->t('Purge'),
        'url' => $url,
        'weight' => 5,
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 880,
          ]),
        ],
      ];
    }
  }

  /**
   * Implements hook_entity_view().
   */
  #[Hook('entity_view')]
  public function entityView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($entity->getEntityTypeId() === 'workspace') {
      foreach (Element::children($build['changes']['list']) as $key) {
        $tracked_entity = $build['changes']['list'][$key]['#entity'];
        if ($this->trashManager->isEntityTypeEnabled($tracked_entity->getEntityType(), $tracked_entity->bundle()) && Trash::entityHasDeletedTranslations($tracked_entity)) {
          // Highlight fully or partially deleted entities in the workspace
          // changes page.
          $build['changes']['list'][$key]['#attributes']['style'] = 'color: #a51b00; background-color: #fcf4f2;';
        }
      }
    }

    if (Trash::entityIsDeleted($entity)) {
      $build['#attributes']['class'][] = 'is-deleted';
      $build['#attached']['library'][] = 'trash/trash';
    }
  }

  /**
   * Implements hook_entity_presave().
   *
   * Converts translation removals into soft-deletes by re-adding the removed
   * translations with a 'deleted' timestamp, which stops core's
   * translation_delete hook from hard-deleting their rows. For live saves,
   * the trash delete events fire with the affected langcodes: the pre events
   * here, the post events in entityUpdate().
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    // Early return conditions.
    if ($entity->isNew() ||
      ($entity instanceof SynchronizableInterface && $entity->isSyncing()) ||
      $this->trashManager->getTrashContext() !== 'active' ||
      !$entity instanceof TranslatableInterface ||
      !$entity->getEntityType()->isTranslatable() ||
      !$entity instanceof TranslationStatusInterface ||
      !$this->trashManager->isEntityTypeEnabled($entity->getEntityType(), $entity->bundle())
    ) {
      return;
    }

    // Detect removed translations. This only needs the translation status, so
    // saves that do not remove any translation skip the original handling
    // below entirely.
    $removed_langcodes = [];
    foreach (array_keys($this->languageManager->getLanguages()) as $langcode) {
      if ($entity->getTranslationStatus($langcode) === TranslationStatusInterface::TRANSLATION_REMOVED) {
        $removed_langcodes[] = (string) $langcode;
      }
    }
    if (empty($removed_langcodes)) {
      return;
    }

    // The removed translations are re-added below with their last saved field
    // values, which have to come from the revision this entity object was
    // loaded from. Core populates the original with the default revision, so
    // reload the baseline when a pending revision is being saved. The reload
    // runs in the 'ignore' context because the loaded revision may be
    // soft-deleted.
    $revisionable = $entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable();
    $original = $entity->getOriginal();
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if ($revisionable) {
      $loaded_revision_id = $entity->getLoadedRevisionId() ?? $entity->getRevisionId();
      if ($loaded_revision_id !== NULL && (!$original instanceof RevisionableInterface || $original->getRevisionId() != $loaded_revision_id)) {
        assert($storage instanceof RevisionableStorageInterface);
        $original = $this->trashManager->executeInTrashContext('ignore', fn () => $storage->loadRevision($loaded_revision_id));
      }
    }
    elseif ($original === NULL) {
      $original = $this->trashManager->executeInTrashContext('ignore', fn () => $storage->loadUnchanged($entity->id()));
    }

    // Without a stored baseline there are no translation values to preserve,
    // so let core handle the save.
    if (!$original instanceof TranslatableInterface) {
      return;
    }
    if ($entity->getOriginal() === NULL) {
      $entity->setOriginal(clone $original);
    }

    // Only translations present in the baseline can be re-added.
    $removed_langcodes = array_filter($removed_langcodes, fn (string $langcode): bool => $original->hasTranslation($langcode));
    if (empty($removed_langcodes)) {
      return;
    }

    // Re-add each removed translation marked as deleted.
    // Re-adding flips their status back to TRANSLATION_EXISTING, which stops
    // core's translation_delete hook from hard-deleting the row. Any other
    // changes in the same save, made by the caller or by earlier presave
    // code, are kept and saved as usual.
    $request_time = $this->time->getRequestTime();

    // Record the soft-delete as one new revision.
    if ($revisionable) {
      $entity->setNewRevision(TRUE);

      if ($entity instanceof RevisionLogInterface) {
        $names = array_map(
          fn (string $langcode): string => $original->getTranslation($langcode)->language()->getName(),
          $removed_langcodes,
        );
        $entity->setRevisionUserId($this->currentUser->id());
        $entity->setRevisionCreationTime($request_time);
        $entity->setRevisionLogMessage($this->t('Deleted translation: @languages', [
          '@languages' => implode(', ', $names),
        ]));
      }
    }

    foreach ($removed_langcodes as $langcode) {
      // The storage restores the stored values instead of initializing new
      // ones, so the re-added translation holds exactly what it held when it
      // was last saved.
      // @see \Drupal\trash\TrashStorageTrait::createTranslation()
      $values = ['deleted' => $request_time] + $original->getTranslation($langcode)->toArray();
      $translation = $entity->addTranslation($langcode, $values);

      if ($revisionable) {
        assert($translation instanceof TranslatableRevisionableInterface);
        $translation->setRevisionTranslationAffected(TRUE);
      }
    }

    // Update original values to avoid hasTranslationChanges() issues.
    if ($entity instanceof ContentEntityBase) {
      $entity->updateOriginalValues();
    }

    // Fire the trash lifecycle events for the soft-deleted translations, so
    // integrations (path aliases, pathauto) can react like they do for a
    // whole-entity trash. The pre events fire here, the post events in
    // entityUpdate(), once the save has been persisted.
    //
    // The only saves that must not fire them are drafts: pending revisions
    // outside a workspace. A draft does not change what is live, but the side
    // effects would, because other entities have no draft scope to join.
    // Workspace saves are pending revisions too, but they do fire: they are
    // the workspace's own publishable state and the side effects land in the
    // same workspace.
    //
    // Skipping drafts has a known limitation: when the draft later becomes
    // the default revision, no events fire then either, so for example the
    // trashed translation's alias stays live and points to an inaccessible
    // translation.
    $is_draft_save = FALSE;
    if ($revisionable) {
      $in_active_workspace = $this->workspaceManager?->hasActiveWorkspace()
        && $this->workspaceInformation?->isEntitySupported($entity);
      $is_draft_save = !$entity->isDefaultRevision() && !$in_active_workspace;
    }
    if (!$is_draft_save) {
      $this->trashManager->getHandler($entity->getEntityTypeId())?->preTrashDelete($entity, $removed_langcodes);
      $this->moduleHandler->invokeAll($entity->getEntityTypeId() . '_pre_trash_delete', [$entity, $removed_langcodes]);
      $this->moduleHandler->invokeAll('entity_pre_trash_delete', [$entity, $removed_langcodes]);
      $this->pendingTranslationTrash[$entity] = $removed_langcodes;
    }
  }

  /**
   * Implements hook_entity_update().
   *
   * Completes the translation soft-deletes started in entityPresave() by
   * firing the post trash delete events once the save has been persisted.
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    if (!isset($this->pendingTranslationTrash[$entity])) {
      return;
    }
    $langcodes = $this->pendingTranslationTrash[$entity];
    unset($this->pendingTranslationTrash[$entity]);

    // Only keep langcodes whose translation really ended up trashed, so a
    // caller that re-added or restored one between presave and now is honored.
    $langcodes = array_values(array_filter(
      $langcodes,
      fn (string $langcode): bool => $entity instanceof TranslatableInterface
        && $entity->hasTranslation($langcode)
        && Trash::entityIsDeleted($entity->getTranslation($langcode)),
    ));
    if ($langcodes === []) {
      return;
    }

    $this->trashManager->getHandler($entity->getEntityTypeId())?->postTrashDelete($entity, $langcodes);
    $this->moduleHandler->invokeAll($entity->getEntityTypeId() . '_trash_delete', [$entity, $langcodes]);
    $this->moduleHandler->invokeAll('entity_trash_delete', [$entity, $langcodes]);
  }

  /**
   * Implements hook_entity_predelete().
   */
  #[Hook('entity_predelete')]
  public function entityPredelete(EntityInterface $entity): void {
    // Only check when trash context is active (not during purge).
    if ($this->trashManager->getTrashContext() !== 'active') {
      return;
    }

    if (!$this->trashManager->isEntityTypeEnabled($entity->getEntityTypeId(), $entity->bundle())) {
      return;
    }

    // Block entity deletion if trash is enabled but storage class generation
    // failed. This prevents silent data loss.
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if (!isset(class_uses($storage)[TrashStorageTrait::class])) {
      throw new \RuntimeException(sprintf(
        'Trash is enabled for %s but storage class generation failed. Delete blocked to prevent data loss.',
        $entity->getEntityTypeId()
      ));
    }
  }

}
