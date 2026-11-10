<?php

declare(strict_types=1);

namespace Drupal\trash_test;

use Drupal\Core\Entity\ContentEntityStorageInterface;

/**
 * Defines an interface for trash_test_entity storage.
 *
 * This interface is used to test that the storage proxy correctly implements
 * entity-type-specific interfaces.
 */
interface TrashTestEntityStorageInterface extends ContentEntityStorageInterface {

  /**
   * Returns a custom test value.
   *
   * @return string
   *   A test value.
   */
  public function getCustomTestValue(): string;

}
