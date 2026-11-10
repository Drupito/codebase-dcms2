<?php

declare(strict_types=1);

namespace Drupal\trash_test;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Defines the storage handler for trash_test_entity entities.
 *
 * This storage class implements TrashTestEntityStorageInterface to test
 * that the storage proxy correctly implements entity-type-specific interfaces.
 */
class TrashTestEntityStorage extends SqlContentEntityStorage implements TrashTestEntityStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function getCustomTestValue(): string {
    return 'custom_test_value_from_storage';
  }

}
