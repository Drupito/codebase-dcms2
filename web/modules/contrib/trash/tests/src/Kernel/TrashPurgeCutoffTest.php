<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\trash\Plugin\QueueWorker\TrashEntityPurgeWorker;
use Drupal\trash_test\Entity\TrashTestEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the purge queue worker cutoff on a monolingual site.
 *
 * Without multiple configured languages the worker takes its whole-entity
 * path, which must honor the auto-purge cutoff just like the per-translation
 * path.
 *
 * @see \Drupal\trash\Plugin\QueueWorker\TrashEntityPurgeWorker
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashPurgeCutoffTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected bool $installNode = FALSE;

  /**
   * Only entities deleted before the cutoff are purged.
   */
  public function testCutoffIsHonored(): void {
    $now = \Drupal::time()->getRequestTime();
    $cutoff = $now - (30 * 86400);
    $recent = $now - 86400;
    $old_id = $this->createDeletedEntity($now - (40 * 86400));
    $recent_id = $this->createDeletedEntity($recent);

    // A legacy queue item without a cutoff purges nothing; the next cron run
    // re-enqueues the entities with a real cutoff.
    $this->runWorker([$old_id, $recent_id], NULL);
    $this->assertNotNull($this->loadTrashedEntity('trash_test_entity', $old_id), 'Legacy queue item without a cutoff purges nothing.');

    $this->runWorker([$old_id, $recent_id], $cutoff);
    $this->assertNull($this->loadTrashedEntity('trash_test_entity', $old_id), 'Entity past the purge window was purged.');
    $entity = $this->loadTrashedEntity('trash_test_entity', $recent_id);
    $this->assertNotNull($entity, 'Recently trashed entity survives a stale queue item.');
    $this->assertSame($recent, (int) $entity->get('deleted')->value);
  }

  /**
   * Creates an entity with the given deleted timestamp.
   */
  private function createDeletedEntity(int $timestamp): string {
    $entity = TrashTestEntity::create(['label' => $this->randomString()]);
    $entity->set('deleted', $timestamp);
    $entity->save();
    return $entity->id();
  }

  /**
   * Runs the purge worker for the given entities with the given cutoff.
   *
   * @param string[] $ids
   *   The entity IDs for the queue item batch.
   * @param int|null $cutoff
   *   The cutoff timestamp, or NULL to build a legacy item without one.
   */
  private function runWorker(array $ids, ?int $cutoff): void {
    $worker = TrashEntityPurgeWorker::create(
      $this->container,
      [],
      'trash_entity_purge',
      ['id' => 'trash_entity_purge'],
    );
    $worker->setStringTranslation($this->container->get('string_translation'));
    $item = [
      'batch' => $ids,
      'entity_type_id' => 'trash_test_entity',
    ];
    if ($cutoff !== NULL) {
      $item['cutoff'] = $cutoff;
    }
    $worker->processItem($item);
  }

}
