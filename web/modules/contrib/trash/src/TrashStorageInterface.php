<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Core\Entity\ContentEntityStorageInterface;

/**
 * Storage interface for trash-enabled entity types.
 */
interface TrashStorageInterface extends ContentEntityStorageInterface {

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
  public function restoreFromTrash(array $entities, array $langcodes = []);

}
