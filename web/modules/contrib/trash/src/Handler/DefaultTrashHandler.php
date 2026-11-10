<?php

declare(strict_types=1);

namespace Drupal\trash\Handler;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\trash\Exception\UnrestorableEntityException;
use Drupal\trash\File\FileIntegrationTrait;
use Drupal\trash\MenuLinkContent\MenuLinkContentIntegrationTrait;
use Drupal\trash\PathAlias\PathAliasIntegrationTrait;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;
use Drupal\trash\Validation\TrashAwareUniqueFieldValueValidator;

/**
 * Provides the default trash handler.
 */
class DefaultTrashHandler implements TrashHandlerInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;
  use FileIntegrationTrait;
  use MenuLinkContentIntegrationTrait;
  use PathAliasIntegrationTrait;

  /**
   * The ID of the entity type managed by this handler.
   */
  protected string $entityTypeId;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The trash manager.
   */
  protected TrashManagerInterface $trashManager;

  /**
   * Tracks whether validation has already been performed for an entity.
   *
   * This prevents duplicate validation when validateRestore() is called
   * explicitly (e.g., from form validation) before preTrashRestore().
   *
   * @var array<string, bool>
   */
  protected array $validatedEntities = [];

  /**
   * {@inheritdoc}
   */
  public function preTrashDelete(EntityInterface $entity, array $langcodes): void {
    // Ensure that Pathauto doesn't try to auto-create aliases when deleting an
    // entity.
    $this->skipPathauto($entity, $langcodes);
  }

  /**
   * {@inheritdoc}
   */
  public function postTrashDelete(EntityInterface $entity, array $langcodes): void {
    // Automatically delete associated path aliases to match core's behavior.
    $this->deleteAssociatedPathAliases($entity, $langcodes);

    // A menu link is a single entity covering all languages of its target, so
    // it is only trashed once no live translation remains.
    if (Trash::entityHasAllTranslationsDeleted($entity)) {
      $this->deleteAssociatedMenuLinkContent($entity);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateRestore(EntityInterface $entity): void {
    // Run entity validation for fieldable entities to check for conflicts.
    if ($entity instanceof FieldableEntityInterface) {
      $violations = $entity->validate();

      // Find only violations from constraints validated by
      // TrashAwareUniqueFieldValueValidator. This catches any constraint whose
      // validatedBy() returns TrashAwareUniqueFieldValueValidator itself or a
      // parent class (e.g., UniqueFieldValueValidator, which is aliased to it
      // via the service container).
      foreach ($violations as $violation) {
        $validator_class = ltrim($violation->getConstraint()->validatedBy(), '\\');
        if (is_a(TrashAwareUniqueFieldValueValidator::class, $validator_class, TRUE)) {
          throw new UnrestorableEntityException((string) $violation->getMessage());
        }
      }
    }

    $this->markRestoreValidated($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function preTrashRestore(EntityInterface $entity, array $langcodes): void {
    $this->ensureRestoreValidated($entity);

    // Ensure that Pathauto doesn't try to auto-create aliases when restoring an
    // entity.
    $this->skipPathauto($entity, $langcodes);
  }

  /**
   * Marks $entity as having passed restore validation in this request.
   *
   * Only call this once validateRestore() has actually succeeded: an
   * exception thrown before reaching this point leaves the entity unmarked,
   * so a later call re-validates instead of trusting a failed check.
   */
  protected function markRestoreValidated(EntityInterface $entity): void {
    $entity_key = $entity->getEntityTypeId() . ':' . $entity->id();
    $this->validatedEntities[$entity_key] = TRUE;
  }

  /**
   * Runs validateRestore() unless it has already succeeded for $entity.
   *
   * Every restore path (both forms, Drush, and the Trash::restoreEntity()
   * API) calls preTrashRestore(), so handlers that call this from their own
   * preTrashRestore() are guaranteed to validate exactly once per restore,
   * regardless of which path triggered it.
   */
  protected function ensureRestoreValidated(EntityInterface $entity): void {
    $entity_key = $entity->getEntityTypeId() . ':' . $entity->id();
    if (empty($this->validatedEntities[$entity_key])) {
      $this->validateRestore($entity);
    }
    unset($this->validatedEntities[$entity_key]);
  }

  /**
   * {@inheritdoc}
   */
  public function postTrashRestore(EntityInterface $entity, array $deleted_timestamps): void {
    // Automatically restore associated path aliases, content menu links and
    // files that were trashed together with the entity.
    $this->restoreAssociatedPathAliases($entity, $deleted_timestamps);
    $this->restoreAssociatedMenuLinkContent($entity, $deleted_timestamps);
    $this->restoreAssociatedFiles($entity, $deleted_timestamps);
  }

  /**
   * Gets the translation objects for the affected langcodes.
   *
   * Only the affected translations are returned, so a partial operation
   * (e.g. a single removed translation) never touches the aliases, files or
   * pathauto state of the other, live translations.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being trashed or restored.
   * @param string[] $langcodes
   *   The langcodes of the affected translations.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface[]
   *   The translation objects to handle, keyed by langcode. For entities that
   *   are not translatable, the entity itself under its own langcode.
   */
  protected function getHandledTranslations(EntityInterface $entity, array $langcodes): array {
    assert($entity instanceof FieldableEntityInterface);
    if (!$entity instanceof TranslatableInterface || !$entity->getEntityType()->isTranslatable()) {
      return [$entity->language()->getId() => $entity];
    }

    $translations = [];
    foreach ($langcodes as $langcode) {
      if ($entity->hasTranslation($langcode)) {
        $translations[$langcode] = $entity->getTranslation($langcode);
      }
    }
    return $translations;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFormAlter(array &$form, FormStateInterface $form_state, bool $multiple = FALSE): void {
    if ($multiple) {
      return;
    }
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }

    // Warn when a newer pending revision exists. The deletion revision is
    // based on the default revision and becomes the latest one, so pending
    // work stops being surfaced in editing workflows, although it stays in
    // the revision history.
    $entity = $form_object->getEntity();
    if ($entity instanceof RevisionableInterface && !$entity->isNew() && !$entity->isLatestRevision()) {
      $form['trash_pending_revision_warning'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            $this->t('This item has unpublished changes in a newer revision. Moving it to the trash will hide those changes from editing workflows, but they remain in the revision history.'),
          ],
        ],
        '#status_headings' => [
          'warning' => $this->t('Warning message'),
        ],
        '#weight' => -10,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function restoreFormAlter(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function purgeFormAlter(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function setEntityTypeId(string $entity_type_id): static {
    $this->entityTypeId = $entity_type_id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager): static {
    $this->entityTypeManager = $entity_type_manager;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTrashManager(TrashManagerInterface $trash_manager): static {
    $this->trashManager = $trash_manager;
    return $this;
  }

}
