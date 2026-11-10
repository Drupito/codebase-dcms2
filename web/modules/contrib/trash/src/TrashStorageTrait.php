<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\TranslatableRevisionableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TranslationStatusInterface;
use Drupal\Core\Utility\Error;

/**
 * Provides soft-delete and restore capabilities at the storage level.
 *
 * If the entity is revisionable, it will create a new revision when
 * soft-deleting and restoring. If the entity is translatable, all translations
 * are marked as deleted in a single revision.
 *
 * @phpstan-require-extends \Drupal\Core\Entity\Sql\SqlContentEntityStorage
 * @phpstan-require-implements \Drupal\trash\TrashStorageInterface
 */
trait TrashStorageTrait {

  /**
   * {@inheritdoc}
   */
  public function createTranslation(ContentEntityInterface $entity, $langcode, array $values = []) {
    // addTranslation() reports TRANSLATION_EXISTING only when it re-adds a
    // translation that is gone from the entity object but still in storage. Any
    // other case, including a re-add after the row was deleted, is a real
    // creation.
    // @see \Drupal\KernelTests\Core\Entity\EntityTranslationTest::testTranslationStatus()
    if ($entity->getTranslationStatus($langcode) !== TranslationStatusInterface::TRANSLATION_EXISTING) {
      return parent::createTranslation($entity, $langcode, $values);
    }

    // Populate it like the parent, minus hook_entity_field_values_init() and
    // hook_entity_translation_create(). Nothing is being created here, and both
    // hooks replace the stored values: paragraphs swaps in unsaved duplicates,
    // layout_builder empties the layout field. Only translatable fields are
    // written, since the rest are shared with the default translation.
    $translation = $entity->getTranslation($langcode);
    $values[$this->getEntityType()->getKey('langcode')] = $langcode;
    $values[$this->getEntityType()->getKey('default_langcode')] = FALSE;
    foreach ($translation->getTranslatableFields() as $name => $items) {
      if (array_key_exists($name, $values)) {
        $translation->set($name, $values[$name]);
      }
      else {
        $items->applyDefaultValue();
      }
    }

    return $translation;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $entities) {
    if (!$entities || $this->getTrashManager()->getTrashContext() !== 'active') {
      parent::delete($entities);
      return;
    }

    $to_delete = [];
    $to_trash = [];

    foreach ($entities as $entity) {
      if ($this->getTrashManager()->isEntityTypeEnabled($entity->getEntityType(), $entity->bundle())) {
        $to_trash[$entity->id()] = $entity;
      }
      else {
        $to_delete[] = $entity;
      }
    }

    parent::delete($to_delete);

    if (empty($to_trash)) {
      return;
    }

    $field_name = 'deleted';
    $revisionable = $this->getEntityType()->isRevisionable();
    $current_user_id = $this->getCurrentUser()->id();
    $request_time = $this->getTime()->getRequestTime();

    foreach ($to_trash as $entity) {
      // Reload the default revision so the deletion revision is always based
      // on published content, not a pending revision or a stale object. The
      // reload returns NULL when the entity is not visible in the active
      // trash context (already trashed, for example by a concurrent request)
      // or no longer exists, so there is nothing left to soft-delete and it
      // can be skipped.
      if (!$entity = $this->loadUnchanged($entity->id())) {
        continue;
      }

      // Collect the translations that still need to be marked as deleted.
      // Translations that are already trashed keep their original deletion
      // timestamp, so their auto-purge window and the timestamp seen by
      // restore integrations (path aliases, menu links) are not reset.
      $trashed_langcodes = [];
      foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
        if (!Trash::entityIsDeleted($entity->getTranslation($langcode))) {
          $trashed_langcodes[] = $langcode;
        }
      }

      // Nothing left to soft-delete for this entity.
      if ($trashed_langcodes === []) {
        continue;
      }

      // Allow code to run before soft-deleting. The handler can be NULL if no
      // handler is registered yet for this entity type (e.g. trash was just
      // enabled for it in the current request, before the container was
      // rebuilt).
      $this->getTrashManager()->getHandler($this->entityTypeId)?->preTrashDelete($entity, $trashed_langcodes);
      $this->invokeTrashHooks('pre_trash_delete', $entity, $trashed_langcodes);

      // Always create a new revision if the entity type is revisionable.
      if ($revisionable) {
        assert($entity instanceof RevisionableInterface);
        $entity->setNewRevision(TRUE);

        if ($entity instanceof RevisionLogInterface) {
          $entity->setRevisionUserId($current_user_id);
          $entity->setRevisionCreationTime($request_time);
          $entity->setRevisionLogMessage(new TranslatableMarkup('Deleted.'));
        }
      }

      // Mark all remaining live translations as deleted in a single revision.
      foreach ($trashed_langcodes as $langcode) {
        $translation = $entity->getTranslation($langcode);
        $translation->set($field_name, $request_time);

        if ($revisionable) {
          assert($translation instanceof TranslatableRevisionableInterface);
          $translation->setRevisionTranslationAffected(TRUE);
        }
      }

      $entity->save();

      // Allow code to run after soft-deleting.
      $this->getTrashManager()->getHandler($this->entityTypeId)?->postTrashDelete($entity, $trashed_langcodes);
      $this->invokeTrashHooks('trash_delete', $entity, $trashed_langcodes);
    }
  }

  /**
   * Restores soft-deleted entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   An array of entity objects to restore.
   * @param string[] $langcodes
   *   The translations to restore, defaults to all.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures, an exception is thrown.
   */
  public function restoreFromTrash(array $entities, array $langcodes = []) {
    try {
      $transaction = $this->database->startTransaction();
      // Restore inside the 'ignore' context so both the reload below and the
      // original that save() loads internally can see the still-trashed rows.
      $this->getTrashManager()->executeInTrashContext('ignore', function () use ($entities, $langcodes) {
        $field_name = 'deleted';
        $revisionable = $this->getEntityType()->isRevisionable();
        $current_user_id = $this->getCurrentUser()->id();
        $request_time = $this->getTime()->getRequestTime();

        foreach ($entities as $entity) {
          // Reload the stored state, so restoring does not depend on the passed
          // object being fresh. The reload returns NULL when the entity no
          // longer exists (e.g. it was purged since it was selected for
          // restore), so there is nothing left to restore.
          if (!$entity = $this->loadUnchanged($entity->id())) {
            continue;
          }

          // Collect the translations that are actually deleted, so a no-op
          // restore doesn't create an empty revision or fire hooks.
          $restore_langcodes = $langcodes ?: array_keys($entity->getTranslationLanguages());

          // A partial restore always includes the default translation when
          // it is deleted. Live translations must never sit on top of a
          // deleted default translation, because purging that default would
          // take the live translations with it.
          $default_langcode = $entity->getUntranslated()->language()->getId();
          if (!in_array($default_langcode, $restore_langcodes, TRUE) && Trash::entityIsDeleted($entity->getUntranslated())) {
            $restore_langcodes[] = $default_langcode;
          }
          // Record each restored translation's deletion timestamp, keyed by
          // langcode. Translations can be trashed at different times, so
          // post-restore integrations need every timestamp to match related
          // entities that were trashed together with a given translation.
          $deleted_timestamps = [];
          foreach ($restore_langcodes as $langcode) {
            if (!$entity->hasTranslation($langcode)) {
              continue;
            }
            $translation = $entity->getTranslation($langcode);
            if (!Trash::entityIsDeleted($translation)) {
              continue;
            }
            $deleted_timestamps[$langcode] = $translation->get($field_name)->value;
          }

          // Nothing to restore for this entity.
          if ($deleted_timestamps === []) {
            continue;
          }
          $restored_langcodes = array_keys($deleted_timestamps);

          // Allow code to run before restoring from trash.
          $this->getTrashManager()->getHandler($this->entityTypeId)?->preTrashRestore($entity, $restored_langcodes);
          $this->invokeTrashHooks('pre_trash_restore', $entity, $restored_langcodes);

          // Always create a new revision if the entity type is revisionable.
          if ($revisionable) {
            assert($entity instanceof RevisionableInterface);
            $entity->setNewRevision(TRUE);

            // Set revision log once (shared across translations).
            if ($entity instanceof RevisionLogInterface) {
              $entity->setRevisionUserId($current_user_id);
              $entity->setRevisionCreationTime($request_time);
              $entity->setRevisionLogMessage(new TranslatableMarkup('Restored from trash'));
            }
          }

          foreach ($restored_langcodes as $langcode) {
            $translation = $entity->getTranslation($langcode);
            $translation->set($field_name, NULL);

            if ($revisionable) {
              assert($translation instanceof TranslatableRevisionableInterface);
              $translation->setRevisionTranslationAffected(TRUE);
            }
          }

          $entity->save();

          // Allow code to run after restoring from trash.
          $this->getTrashManager()->getHandler($this->entityTypeId)?->postTrashRestore($entity, $deleted_timestamps);
          $this->invokeTrashHooks('trash_restore', $entity, $deleted_timestamps);
        }
      });
    }
    catch (\Throwable $e) {
      // Catch \Throwable, not just \Exception: an \Error here (e.g. a
      // TypeError from a handler or a hook implementation) would otherwise
      // skip the rollback and let the transaction commit on stack unwind.
      if (isset($transaction)) {
        $transaction->rollBack();
        // Each save inside the transaction updated the static and persistent
        // entity caches, and post-restore handlers may have re-populated them
        // with the uncommitted restored state. Drop those entries so they are
        // rebuilt from the rolled-back database state.
        $this->resetCache(array_map(static fn ($entity) => $entity->id(), $entities));
      }
      // Log under the entity type's own channel (e.g. 'node', 'file'), not
      // 'trash', so restore failures land with that entity's other logs.
      Error::logException(\Drupal::logger($this->entityTypeId), $e);
      throw $e;
    }
  }

  /**
   * Invokes the entity-type-specific and generic variants of a trash hook.
   *
   * The storage's invokeHook() passes only the entity, so the trash hooks
   * invoke the module handler directly in order to carry translation info.
   *
   * @param string $hook
   *   One of 'pre_trash_delete', 'trash_delete', 'pre_trash_restore' or
   *   'trash_restore'.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being soft-deleted or restored.
   * @param array $context
   *   The affected langcodes, except for 'trash_restore', which gets the
   *   deletion timestamps of the restored translations, keyed by langcode.
   */
  private function invokeTrashHooks(string $hook, EntityInterface $entity, array $context): void {
    $this->moduleHandler()->invokeAll($this->entityTypeId . '_' . $hook, [$entity, $context]);
    $this->moduleHandler()->invokeAll('entity_' . $hook, [$entity, $context]);
  }

  /**
   * Gets the trash manager service.
   */
  private function getTrashManager(): TrashManagerInterface {
    return \Drupal::service('trash.manager');
  }

  /**
   * Gets the time service.
   */
  private function getTime(): TimeInterface {
    return \Drupal::time();
  }

  /**
   * Gets the current user.
   */
  private function getCurrentUser(): AccountInterface {
    return \Drupal::currentUser();
  }

}
