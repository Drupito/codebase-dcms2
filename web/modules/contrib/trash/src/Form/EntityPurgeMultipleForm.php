<?php

declare(strict_types=1);

namespace Drupal\trash\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityInterface;
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
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a confirmation form for permanently deleting multiple entities.
 */
class EntityPurgeMultipleForm extends ConfirmFormBase implements BaseFormIdInterface, WorkspaceDynamicSafeFormInterface {

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
    $this->tempStore = $tempStoreFactory->get('trash_purge_multiple_confirm');
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    return 'trash_entity_purge_multiple_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    $entity_type_id = $this->getRouteMatch()->getParameter('entity_type_id');
    return $entity_type_id . '_trash_purge_multiple_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(
      count($this->selection),
      'Are you sure you want to permanently delete this @item?',
      'Are you sure you want to permanently delete these @items?',
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
  public function getConfirmText() {
    return $this->t('Permanently delete');
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
      foreach ($selected_langcodes as $langcode) {
        $key = $id . ':' . $langcode;
        if ($entity instanceof TranslatableInterface) {
          // A single translation may have been purged since the confirm page
          // selection was built (another user or tab, or the
          // delete_translations path below); skip it instead of fatally
          // erroring in getTranslation().
          if (!$entity->hasTranslation($langcode)) {
            continue;
          }
          $entity = $entity->getTranslation($langcode);
          $default_key = $id . ':' . $entity->getUntranslated()->language()->getId();

          // Build a nested list of translations that will be deleted if the
          // entity has multiple translations.
          $entity_languages = $entity->getTranslationLanguages();
          if (count($entity_languages) > 1 && $entity->isDefaultTranslation()) {
            $names = [];
            foreach ($entity_languages as $translation_langcode => $language) {
              $names[] = $language->getName();
              unset($items[$id . ':' . $translation_langcode]);
            }
            $items[$default_key] = [
              'label' => [
                '#markup' => $this->t('@label (Original translation) - <em>The following @entity_type translations will be deleted:</em>',
                  [
                    '@label' => $entity->label(),
                    '@entity_type' => $this->entityType->getSingularLabel(),
                  ]),
              ],
              'deleted_translations' => [
                '#theme' => 'item_list',
                '#items' => $names,
              ],
            ];
          }
          elseif (!isset($items[$default_key])) {
            $items[$key] = $entity->label();
          }
        }
        elseif (!isset($items[$key])) {
          $items[$key] = $entity->label();
        }
      }
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
    $delete_entities = [];
    $delete_translations = [];
    $inaccessible_entities = [];

    /** @var \Drupal\Core\Entity\EntityInterface[] $entities */
    $entities = $this->entityTypeManager->getStorage($this->entityTypeId)->loadMultiple(array_keys($this->selection));

    foreach ($this->selection as $id => $selected_langcodes) {
      // The entity may have been purged since the confirm page was built;
      // skip it instead of fatally erroring on the missing array key.
      if (!isset($entities[$id])) {
        continue;
      }
      $entity = $entities[$id];
      foreach ($selected_langcodes as $langcode) {
        if ($entity instanceof TranslatableInterface) {
          // A single translation may have been purged since the confirm page
          // was built; skip it instead of fatally erroring in getTranslation().
          if (!$entity->hasTranslation($langcode)) {
            continue;
          }
          $entity = $entity->getTranslation($langcode);
        }
        if (!$entity->access('purge', $this->currentUser)) {
          $inaccessible_entities[] = $entity;
          continue;
        }
        if ($entity instanceof TranslatableInterface) {
          if ($entity->isDefaultTranslation()) {
            $delete_entities[$id] = $entity;
            unset($delete_translations[$id]);
            $total_count += count($entity->getTranslationLanguages());
          }
          elseif (!isset($delete_entities[$id])) {
            $delete_translations[$id][] = $entity;
          }
        }
        elseif (!isset($delete_entities[$id])) {
          $delete_entities[$id] = $entity;
          $total_count++;
        }
      }
    }

    // A translation whose own access check was denied may still belong to an
    // entity whose default translation was granted and selected: purging the
    // default destroys every translation regardless, so don't report it as
    // undeleted.
    $inaccessible_entities = array_filter($inaccessible_entities, static fn ($entity) => !isset($delete_entities[$entity->id()]));

    if ($delete_entities) {
      $storage = $this->entityTypeManager->getStorage($this->entityTypeId);
      $storage->delete($delete_entities);
      foreach ($delete_entities as $entity) {
        $this->logger('trash')->info('Permanently deleted @entity-type %label.', [
          '@entity-type' => $entity->getEntityType()->getSingularLabel(),
          '%label' => $entity->label(),
        ]);
      }
    }

    if ($delete_translations) {
      foreach ($delete_translations as $id => $translations) {
        $entity = $entities[$id];
        // Only translatable entities reach $delete_translations, but that is
        // decided in the loop above and can't be seen from here.
        assert($entity instanceof ContentEntityInterface);
        $entity = $entity->getUntranslated();
        foreach ($translations as $translation) {
          $entity->removeTranslation($translation->language()->getId());
        }
        $entity->save();
        foreach ($translations as $translation) {
          $this->logger('trash')->info('Permanently deleted @entity-type %label @language translation.', [
            '@entity-type' => $entity->getEntityType()->getSingularLabel(),
            '%label' => $entity->label(),
            '@language' => $translation->language()->getName(),
          ]);
        }
        $total_count += count($translations);
      }
    }

    if ($total_count) {
      $this->messenger()->addStatus($this->getDeletedMessage($total_count));
    }
    if ($inaccessible_entities) {
      $this->messenger()->addWarning($this->getInaccessibleMessage(count($inaccessible_entities)));
    }

    $this->tempStore->delete($this->currentUser->id() . ':' . $this->entityTypeId);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Returns the message to show the user after an item was deleted.
   *
   * @param int $count
   *   Count of deleted items.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The item deleted message.
   */
  protected function getDeletedMessage(int $count) {
    return $this->formatPlural($count, 'Permanently deleted @count item.', 'Permanently deleted @count items.');
  }

  /**
   * Returns the message to show the user when an item has not been deleted.
   *
   * @param int $count
   *   Count of inaccessible items.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The item inaccessible message.
   */
  protected function getInaccessibleMessage(int $count) {
    return $this->formatPlural($count, '@count item has not been deleted because you do not have the necessary permissions.', '@count items have not been deleted because you do not have the necessary permissions.');
  }

  /**
   * {@inheritdoc}
   */
  public function isWorkspaceSafeForm(array $form, FormStateInterface $form_state): bool {
    return $this->isWorkspaceSafeEntityType($this->entityType);
  }

}
