<?php

declare(strict_types=1);

namespace Drupal\trash\File;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\trash\TrashStorageInterface;

/**
 * Provides file entity integration for trash handlers.
 *
 * Trash does not delete referenced files when an entity is trashed; that is
 * left to the caller (a user action or a module such as media_file_delete).
 * When it does happen in the same request, the files share the entity's
 * deletion timestamp, and this trait uses that to bring them back on restore.
 *
 * @phpstan-require-extends \Drupal\trash\Handler\DefaultTrashHandler
 */
trait FileIntegrationTrait {

  /**
   * Whether file entities should be handled for the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if file entities should be handled, FALSE otherwise.
   */
  protected function shouldHandleFiles(EntityInterface $entity): bool {
    return $entity instanceof FieldableEntityInterface
      && $entity->getEntityTypeId() !== 'file'
      && $this->trashManager->isEntityTypeEnabled('file');
  }

  /**
   * Returns the file entity IDs referenced by the given translation.
   *
   * Every entity reference field targeting files counts, including base
   * fields such as the media thumbnail, so a shared thumbnail file that was
   * trashed together with the entity comes back with it.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $translation
   *   The entity translation whose fields to inspect.
   *
   * @return int[]
   *   The referenced file IDs, without duplicates.
   */
  protected function getReferencedFileIds(FieldableEntityInterface $translation): array {
    $file_ids = [];
    foreach ($translation->getFields() as $field) {
      if (!$field instanceof EntityReferenceFieldItemListInterface
        || $field->getFieldDefinition()->getSetting('target_type') !== 'file') {
        continue;
      }
      foreach ($field as $item) {
        if ($item->target_id !== NULL) {
          $file_ids[] = (int) $item->target_id;
        }
      }
    }

    return array_values(array_unique($file_ids));
  }

  /**
   * Restores files that were trashed together with the restored translations.
   *
   * A file is restored when a restored translation references it and its
   * deletion timestamp matches that translation's. Files trashed on their own
   * keep a different timestamp and stay in the trash, and so do files that
   * only belong to translations which are still trashed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being restored.
   * @param array<string, int|string> $deleted_timestamps
   *   The deletion timestamps of the restored translations, keyed by
   *   langcode.
   */
  protected function restoreAssociatedFiles(EntityInterface $entity, array $deleted_timestamps): void {
    if (!$this->shouldHandleFiles($entity)) {
      return;
    }

    $file_storage = $this->entityTypeManager->getStorage('file');
    $query = $file_storage->getQuery()->accessCheck(FALSE);
    $conditions = $query->orConditionGroup();
    foreach ($this->getHandledTranslations($entity, array_keys($deleted_timestamps)) as $langcode => $translation) {
      $file_ids = $this->getReferencedFileIds($translation);
      if ($file_ids !== []) {
        $conditions->condition($query->andConditionGroup()
          ->condition('fid', $file_ids, 'IN')
          ->condition('deleted', $deleted_timestamps[$langcode])
        );
      }
    }
    if ($conditions->count() === 0) {
      return;
    }

    $ids = $query->condition($conditions)->execute();
    if (empty($ids)) {
      return;
    }

    $files = $this->trashManager->executeInTrashContext('ignore', function () use ($file_storage, $ids) {
      return $file_storage->loadMultiple($ids);
    });
    assert($file_storage instanceof TrashStorageInterface);
    $file_storage->restoreFromTrash(array_values($files));
  }

}
