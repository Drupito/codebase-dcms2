<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\trash\Hook\TrashHandler\NodeTrashHandler;
use Drupal\trash\TrashEntityPurger;
use Drupal\trash\TrashManagerInterface;
use Drupal\trash\TrashStorageTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that certain Trash services can be serialized and unserialized.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashSerializationTest extends TrashKernelTestBase {

  /**
   * Tests that the decorated 'entity_type.manager' service can be serialized.
   */
  public function testSerializeEntityTypeManager(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('node');
    $serialized_data = serialize($entity_type_manager);
    $unserialized_entity_type_manager = unserialize($serialized_data);
    assert($unserialized_entity_type_manager instanceof EntityTypeManagerInterface);
    // Ensure that the unserialized service is functional.
    static::assertContains(TrashStorageTrait::class, class_uses($unserialized_entity_type_manager->getStorage('node')));
    // The original service should also be functional.
    static::assertContains(TrashStorageTrait::class, class_uses($this->container->get('entity_type.manager')->getStorage('node')));
  }

  /**
   * Tests that the 'trash.manager' service can be serialized.
   */
  public function testSerializeTrashManager(): void {
    $trash_manager = $this->container->get('trash.manager');
    $serialized_data = serialize($trash_manager);
    $unserialized_trash_manager = unserialize($serialized_data);
    assert($unserialized_trash_manager instanceof TrashManagerInterface);
    // Ensure that the unserialized service is functional.
    static::assertInstanceOf(NodeTrashHandler::class, $unserialized_trash_manager->getHandler('node'));
    static::assertSame(['trash_test_entity', 'node'], $unserialized_trash_manager->getEnabledEntityTypes());
    // The original service should also be functional.
    static::assertInstanceOf(NodeTrashHandler::class, $this->container->get('trash.manager')->getHandler('node'));
    static::assertSame(['trash_test_entity', 'node'], $this->container->get('trash.manager')->getEnabledEntityTypes());
  }

  /**
   * Tests that the trash entity purger service can be serialized.
   */
  public function testSerializeTrashEntityPurger(): void {
    // Backdate the request time so the 'deleted' field on the node is well
    // outside the auto-purge window. The TestTime decorator only affects
    // getRequestTime(), so the purger's getCurrentTime() comparison still
    // uses real time.
    \Drupal::keyValue('trash_test')->set('time_offset', -3600);
    $node = $this->createNode(['type' => 'article']);
    $node->delete();
    \Drupal::keyValue('trash_test')->set('time_offset', 0);

    $this->config('trash.settings')->set('auto_purge.after', '1 second')->save();

    $entity_purger = $this->container->get(TrashEntityPurger::class);
    $queue = $this->container->get('queue')->get(TrashEntityPurger::PURGE_QUEUE_NAME);
    static::assertSame(0, $queue->numberOfItems());

    $serialized_data = serialize($entity_purger);
    $unserialized_entity_purger = unserialize($serialized_data);
    assert($unserialized_entity_purger instanceof TrashEntityPurger);
    // Ensure that the unserialized service is functional.
    $unserialized_entity_purger->populatePurgeQueue('node');
    static::assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    static::assertTrue(is_object($item));
    static::assertSame('node', $item->data['entity_type_id']);
    static::assertSame([$node->id()], array_values($item->data['batch']));
    $queue->deleteItem($item);

    // The original service should also be functional.
    $this->container->get(TrashEntityPurger::class)->populatePurgeQueue('node');
    static::assertSame(1, $queue->numberOfItems());
  }

}
