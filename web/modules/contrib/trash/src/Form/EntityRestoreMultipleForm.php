<?php

declare(strict_types=1);

namespace Drupal\trash\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Form\WorkspaceSafeFormTrait;
use Drupal\Core\Form\BaseFormIdInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\WorkspaceDynamicSafeFormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Url;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a confirmation form for restoring multiple entities from trash.
 */
class EntityRestoreMultipleForm extends ConfirmFormBase implements BaseFormIdInterface, WorkspaceDynamicSafeFormInterface {

  use AutowireTrait;
  use WorkspaceSafeFormTrait;

  /**
   * The entity type ID.
   */
  protected string $entityTypeId;

  /**
   * The entity type definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityType;

  /**
   * The selection, in the entity_id => langcodes format.
   */
  protected array $selection = [];

  /**
   * The tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  public function __construct(
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    PrivateTempStoreFactory $tempStoreFactory,
    protected TrashManagerInterface $trashManager,
  ) {
    $this->tempStore = $tempStoreFactory->get('trash_restore_multiple_confirm');
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    return 'trash_entity_restore_multiple_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    $entity_type_id = $this->getRouteMatch()->getParameter('entity_type_id');
    return $entity_type_id . '_trash_restore_multiple_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(
      count($this->selection),
      'Are you sure you want to restore this @item from trash?',
      'Are you sure you want to restore these @items from trash?',
      [
        '@item' => $this->entityType->getSingularLabel(),
        '@items' => $this->entityType->getPluralLabel(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('trash.admin_content_trash_entity_type', [
      'entity_type_id' => $this->entityTypeId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    // @phpstan-ignore return.type
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Restore');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type_id = NULL) {
    $this->entityTypeId = $entity_type_id;
    $this->selection = $this->tempStore->get($this->currentUser->id() . ':' . $entity_type_id) ?? [];

    if (empty($this->entityTypeId) || empty($this->selection)) {
      // @phpstan-ignore return.type
      return new RedirectResponse($this->getCancelUrl()->setAbsolute()->toString());
    }

    $this->entityType = $this->entityTypeManager->getDefinition($this->entityTypeId);
    $entities = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple(array_keys($this->selection));

    // Entities may have been purged (by auto-purge cron, another user, or
    // another tab) between the bulk operation and this confirm page, since
    // the tempstore selection survives across requests. Drop them from the
    // selection, and bail out if nothing is left to confirm.
    $this->selection = array_intersect_key($this->selection, $entities);
    if (empty($this->selection)) {
      // @phpstan-ignore return.type
      return new RedirectResponse($this->getCancelUrl()->setAbsolute()->toString());
    }

    $items = [];
    foreach ($this->selection as $id => $selected_langcodes) {
      $entity = $entities[$id];
      $restore_langcodes = $this->getRestoreLangcodes($entity, $selected_langcodes);
      // Nothing left to restore for this entity, for example because it was
      // restored by another user while the selection sat in the tempstore.
      if (!$restore_langcodes) {
        continue;
      }

      // A single translation: show its label directly.
      if (count($restore_langcodes) === 1 || !$entity instanceof TranslatableInterface) {
        $langcode = reset($restore_langcodes);
        $translation = $entity instanceof TranslatableInterface ? $entity->getTranslation($langcode) : $entity;
        $items[$id] = $translation->label();
        continue;
      }

      // List each translation that will be restored, including a default
      // translation that is restored along with a selected translation.
      $names = [];
      foreach ($restore_langcodes as $langcode) {
        $translation = $entity->getTranslation($langcode);
        $names[] = $translation->isDefaultTranslation()
          ? $this->t('@language (Original language)', ['@language' => $translation->language()->getName()])
          : $translation->language()->getName();
      }
      $items[$id] = [
        'label' => [
          '#markup' => $this->t('@label - <em>The following @entity_type translations will be restored:</em>',
            [
              '@label' => $entity->label(),
              '@entity_type' => $this->entityType->getSingularLabel(),
            ]),
        ],
        'restored_translations' => [
          '#theme' => 'item_list',
          '#items' => $names,
        ],
      ];
    }

    $form['entities'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    assert($this->trashManager->getTrashContext() !== 'active');

    $total_count = 0;
    $restore_langcodes = [];
    $inaccessible_entities = [];

    $entities = $this->entityTypeManager->getStorage($this->entityTypeId)->loadMultiple(array_keys($this->selection));

    foreach ($this->selection as $id => $selected_langcodes) {
      // The entity may have been purged since the confirm page was built;
      // skip it instead of fatally erroring on the missing array key.
      if (!isset($entities[$id])) {
        continue;
      }
      $entity = $entities[$id];
      $accessible_langcodes = [];
      foreach ($selected_langcodes as $langcode) {
        if ($entity instanceof TranslatableInterface) {
          // The translation may have been purged or restored since the
          // selection was made; skip it so the reported count only covers
          // translations this submit actually restores.
          if (!$entity->hasTranslation($langcode)) {
            continue;
          }
          $translation = $entity->getTranslation($langcode);
        }
        else {
          $translation = $entity;
        }
        if (!Trash::entityIsDeleted($translation)) {
          continue;
        }
        if (!$translation->access('restore', $this->currentUser)) {
          $inaccessible_entities[] = $translation;
          continue;
        }
        $accessible_langcodes[] = $langcode;
      }
      // The forced default translation needs no separate access check: restore
      // access on a translation already requires restore access on its deleted
      // default translation.
      if ($langcodes = $this->getRestoreLangcodes($entity, $accessible_langcodes)) {
        $restore_langcodes[$id] = $langcodes;
      }
    }

    foreach ($restore_langcodes as $id => $langcodes) {
      $entity = $entities[$id];
      try {
        Trash::restoreEntity($entity, $langcodes);
        $total_count += count($langcodes);
        $this->logger('trash')->info('Restored @entity-type %label from trash.', [
          '@entity-type' => $entity->getEntityType()->getSingularLabel(),
          '%label' => $entity->label(),
        ]);
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Could not restore @label: @message', [
          '@label' => $entity->label(),
          '@message' => $e->getMessage(),
        ]));
      }
    }

    if ($total_count) {
      $this->messenger()->addStatus($this->getRestoredMessage($total_count));
    }
    if ($inaccessible_entities) {
      $this->messenger()->addWarning($this->getInaccessibleMessage(count($inaccessible_entities)));
    }

    $this->tempStore->delete($this->currentUser->id() . ':' . $this->entityTypeId);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Resolves the langcodes that will actually be restored for an entity.
   *
   * Drops selected langcodes whose translation is missing or not deleted, and
   * adds the default langcode when a selected translation has a deleted
   * default translation. Restoring a translation whose original language is
   * also deleted must restore the original too, otherwise the entity ends up
   * live in one language while still trashed in its default, and a later
   * purge of that still-trashed default would destroy the restored
   * translation.
   *
   * Both the confirm page and the submit handler use this method, so the
   * confirm page lists exactly the translations that get restored.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The selected entity.
   * @param string[] $selected_langcodes
   *   The selected langcodes.
   *
   * @return string[]
   *   The langcodes that will be restored, keyed by langcode.
   */
  protected function getRestoreLangcodes(EntityInterface $entity, array $selected_langcodes): array {
    $langcodes = [];
    foreach ($selected_langcodes as $langcode) {
      if ($entity instanceof TranslatableInterface) {
        if (!$entity->hasTranslation($langcode)) {
          continue;
        }
        $translation = $entity->getTranslation($langcode);
      }
      else {
        $translation = $entity;
      }
      if (!Trash::entityIsDeleted($translation)) {
        continue;
      }
      $langcodes[$langcode] = $langcode;

      if ($translation instanceof TranslatableInterface && !$translation->isDefaultTranslation()) {
        $untranslated = $translation->getUntranslated();
        if (Trash::entityIsDeleted($untranslated)) {
          $default_langcode = $untranslated->language()->getId();
          $langcodes[$default_langcode] = $default_langcode;
        }
      }
    }
    return $langcodes;
  }

  /**
   * Returns the message to show the user after an item was restored.
   *
   * @param int $count
   *   Count of restored items.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The item restored message.
   */
  protected function getRestoredMessage(int $count) {
    return $this->formatPlural($count, 'Restored @count item from trash.', 'Restored @count items from trash.');
  }

  /**
   * Returns the message to show the user when an item has not been restored.
   *
   * @param int $count
   *   Count of inaccessible items.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The item inaccessible message.
   */
  protected function getInaccessibleMessage(int $count) {
    return $this->formatPlural($count, '@count item has not been restored because you do not have the necessary permissions.', '@count items have not been restored because you do not have the necessary permissions.');
  }

  /**
   * {@inheritdoc}
   */
  public function isWorkspaceSafeForm(array $form, FormStateInterface $form_state): bool {
    return $this->isWorkspaceSafeEntityType($this->entityType);
  }

}
