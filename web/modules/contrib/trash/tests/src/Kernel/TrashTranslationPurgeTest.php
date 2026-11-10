<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\trash\Plugin\QueueWorker\TrashEntityPurgeWorker;
use Drupal\trash\Trash;
use Drupal\trash\TrashCliActions;
use Drupal\trash_test\Entity\TrashTestEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests purging and restoring trashed translations.
 *
 * Covers the two batch paths that act on trashed translations: the cron queue
 * worker (which honors an auto-purge cutoff) and the CLI purge/restore
 * operations (which must count per entity, not per chunk).
 *
 * @see \Drupal\trash\Plugin\QueueWorker\TrashEntityPurgeWorker
 * @see \Drupal\trash\TrashCliActions
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashTranslationPurgeTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * Messages logged to the 'trash' channel after self::captureTrashLog().
   *
   * @var string[]
   */
  private array $trashLogRecords = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('sv')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * Only translations deleted before the cutoff are purged by the worker.
   */
  public function testTranslationsPastCutoffOnlyArePurged(): void {
    $now = \Drupal::time()->getRequestTime();
    $cutoff = $now - (30 * 86400);
    $old = $now - (40 * 86400);
    $recent = $now - 86400;

    // English (default) stays live; German is well past the window, Swedish
    // was deleted yesterday and must survive.
    $id = $this->createEntityWithDeletedTranslations([
      'en' => NULL,
      'de' => $old,
      'sv' => $recent,
    ]);

    // A legacy queue item without a cutoff purges nothing and logs nothing;
    // the next cron run re-enqueues the entity with a real cutoff.
    $this->captureTrashLog();
    $this->runWorker($id, NULL);
    $entity = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertTrue($entity->hasTranslation('de'), 'Legacy queue item without a cutoff purges nothing.');
    $this->assertSame([], $this->trashLogRecords);

    $this->runWorker($id, $cutoff);

    $entity = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertNotNull($entity);
    $this->assertFalse($entity->hasTranslation('de'), 'German translation was purged.');
    $this->assertTrue($entity->hasTranslation('sv'), 'Swedish translation survived the cutoff.');
    $this->assertTrue($entity->hasTranslation('en'), 'English translation is untouched.');
    $this->assertEquals($recent, $entity->getTranslation('sv')->get('deleted')->value);
  }

  /**
   * The whole entity is purged only when the default is past the cutoff.
   */
  public function testDefaultTranslationRespectsCutoff(): void {
    $now = \Drupal::time()->getRequestTime();
    $cutoff = $now - (30 * 86400);
    $recent = $now - 86400;

    // Whole-entity soft-delete marks every translation with the same recent
    // timestamp, so nothing is past the window yet: the entity must remain.
    $id = $this->createEntityWithDeletedTranslations([
      'en' => $recent,
      'de' => $recent,
      'sv' => $recent,
    ]);

    $this->runWorker($id, $cutoff);

    $entity = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertNotNull($entity, 'Entity is not purged before the default translation is past the cutoff.');
    $this->assertTrue($entity->hasTranslation('de'));
    $this->assertTrue($entity->hasTranslation('sv'));
  }

  /**
   * A whole-entity purge requires every translation to be deleted and expired.
   */
  public function testWholeEntityPurgeRequiresAllTranslationsDeletedAndExpired(): void {
    $now = \Drupal::time()->getRequestTime();
    $cutoff = $now - (30 * 86400);
    $old = $now - (40 * 86400);
    $recent = $now - 86400;

    // Every translation is deleted and past the window: full purge.
    $all_old = $this->createEntityWithDeletedTranslations([
      'en' => $old,
      'de' => $old,
      'sv' => $old,
    ]);
    // The default translation is expired but German is live: only Swedish may
    // be removed; German and the entity itself must survive.
    $live_translation = $this->createEntityWithDeletedTranslations([
      'en' => $old,
      'de' => NULL,
      'sv' => $old,
    ]);
    // German was re-trashed recently, blocking the whole-entity purge; only
    // Swedish is past the window.
    $recent_translation = $this->createEntityWithDeletedTranslations([
      'en' => $old,
      'de' => $recent,
      'sv' => $old,
    ]);
    // Nothing is past the window, so this entity is skipped entirely.
    $all_recent = $this->createEntityWithDeletedTranslations([
      'en' => $recent,
      'de' => $recent,
      'sv' => $recent,
    ]);

    $this->captureTrashLog();
    $this->runWorker([$all_old, $live_translation, $recent_translation, $all_recent], $cutoff);

    $this->assertNull($this->loadTrashedEntity('trash_test_entity', $all_old), 'Fully expired entity was purged.');

    $entity = $this->loadTrashedEntity('trash_test_entity', $live_translation);
    $this->assertNotNull($entity, 'Entity with a live translation survives a whole-entity purge.');
    $this->assertTrue($entity->hasTranslation('de'), 'Live German translation survived.');
    $this->assertFalse(Trash::entityIsDeleted($entity->getTranslation('de')));
    $this->assertFalse($entity->hasTranslation('sv'), 'Expired Swedish translation was removed.');
    $this->assertTrue(Trash::entityIsDeleted($entity), 'Deleted default translation stays in the trash.');

    $entity = $this->loadTrashedEntity('trash_test_entity', $recent_translation);
    $this->assertNotNull($entity, 'Entity with a recently re-trashed translation survives.');
    $this->assertTrue($entity->hasTranslation('de'), 'Recently re-trashed German translation survived.');
    $this->assertFalse($entity->hasTranslation('sv'), 'Expired Swedish translation was removed.');

    $entity = $this->loadTrashedEntity('trash_test_entity', $all_recent);
    $this->assertNotNull($entity);
    $this->assertTrue($entity->hasTranslation('de'));
    $this->assertTrue($entity->hasTranslation('sv'));

    // The log reports the number of entities actually acted on, not the
    // number of queued IDs.
    $this->assertCount(1, $this->trashLogRecords);
    $this->assertStringContainsString('Successfully purged 3', $this->trashLogRecords[0]);
  }

  /**
   * Trash::restoreEntity() always co-restores a deleted default translation.
   */
  public function testRestoreApiRestoresDeletedDefaultTranslation(): void {
    $now = \Drupal::time()->getRequestTime();
    $id = $this->createEntityWithDeletedTranslations([
      'en' => $now,
      'de' => $now,
      'sv' => $now,
    ]);

    $this->restoreEntity('trash_test_entity', $id, ['de']);

    $entity = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertFalse(Trash::entityIsDeleted($entity->getTranslation('de')), 'Requested translation was restored.');
    $this->assertFalse(Trash::entityIsDeleted($entity->getTranslation('en')), 'Deleted default translation was restored along with the requested one.');
    $this->assertTrue(Trash::entityIsDeleted($entity->getTranslation('sv')), 'The translation we did not restore stays in the trash.');
  }

  /**
   * Purge never hard-deletes entities that still have live translations.
   */
  public function testPurgeKeepsLiveTranslations(): void {
    $now = \Drupal::time()->getRequestTime();
    // Default and Swedish trashed, German live.
    $mixed = $this->createEntityWithDeletedTranslations([
      'en' => $now,
      'de' => NULL,
      'sv' => $now,
    ]);
    // Fully trashed.
    $full = $this->createEntityWithDeletedTranslations([
      'en' => $now,
      'de' => $now,
      'sv' => $now,
    ]);

    $count = $this->runCliOperation('purge');

    $this->assertSame(2, $count);
    $this->assertNull($this->loadTrashedEntity('trash_test_entity', $full), 'Fully trashed entity was purged.');
    $entity = $this->loadTrashedEntity('trash_test_entity', $mixed);
    $this->assertNotNull($entity, 'Entity with a live translation survives the purge.');
    $this->assertTrue($entity->hasTranslation('de'), 'Live German translation survived.');
    $this->assertFalse(Trash::entityIsDeleted($entity->getTranslation('de')));
    $this->assertFalse($entity->hasTranslation('sv'), 'Trashed Swedish translation was removed.');
    $this->assertTrue(Trash::entityIsDeleted($entity), 'The default translation stays in the trash.');
  }

  /**
   * Purge counts each entity, not each chunk.
   */
  public function testPurgeCountsPerEntity(): void {
    // Three trashed entities split across two chunks. Counting per chunk would
    // report 2; counting per entity reports 3.
    $this->setSetting('entity_update_batch_size', 2);
    $this->createDeletedEntities(3);

    $count = $this->runCliOperation('purge');

    $this->assertSame(3, $count);
    $remaining = (int) $this->getEntityTypeManager()->getStorage('trash_test_entity')->getQuery()
      ->accessCheck(FALSE)
      ->addMetaData('trash', 'inactive')
      ->count()
      ->execute();
    $this->assertSame(0, $remaining, 'All trashed entities were purged.');
  }

  /**
   * Restore counts each entity, not each chunk.
   */
  public function testRestoreCountsPerEntity(): void {
    $this->setSetting('entity_update_batch_size', 2);
    $ids = $this->createDeletedEntities(3);

    $count = $this->runCliOperation('restore');

    $this->assertSame(3, $count);
    foreach ($ids as $id) {
      $entity = $this->getEntityTypeManager()->getStorage('trash_test_entity')->load($id);
      $this->assertNotNull($entity, 'Restored entity is no longer trashed.');
    }
  }

  /**
   * Creates a translated entity and marks per-translation deleted timestamps.
   *
   * @param array<string, int|null> $deleted
   *   Deleted timestamps keyed by langcode. NULL means the translation is not
   *   deleted.
   *
   * @return string
   *   The saved entity ID.
   */
  private function createEntityWithDeletedTranslations(array $deleted): string {
    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('sv', ['label' => 'SV']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();

    // Stamp the deleted field per translation directly. No translation is
    // removed, so the presave hook leaves these values in place.
    foreach ($deleted as $langcode => $timestamp) {
      $entity->getTranslation($langcode)->set('deleted', $timestamp);
    }
    $entity->save();

    return $entity->id();
  }

  /**
   * Creates whole-deleted translated entities.
   *
   * @param int $count
   *   How many entities to create.
   *
   * @return string[]
   *   The saved entity IDs.
   */
  private function createDeletedEntities(int $count): array {
    $now = \Drupal::time()->getRequestTime();
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
      $ids[] = $this->createEntityWithDeletedTranslations([
        'en' => $now,
        'de' => $now,
        'sv' => $now,
      ]);
    }
    return $ids;
  }

  /**
   * Runs the purge worker for the given entities with the given cutoff.
   *
   * @param string|string[] $ids
   *   One or more entity IDs for the queue item batch.
   * @param int|null $cutoff
   *   The cutoff timestamp, or NULL to build a legacy item without one.
   */
  private function runWorker(string|array $ids, ?int $cutoff): void {
    $worker = TrashEntityPurgeWorker::create(
      $this->container,
      [],
      'trash_entity_purge',
      ['id' => 'trash_entity_purge'],
    );
    $worker->setStringTranslation($this->container->get('string_translation'));
    $item = [
      'batch' => (array) $ids,
      'entity_type_id' => 'trash_test_entity',
    ];
    if ($cutoff !== NULL) {
      $item['cutoff'] = $cutoff;
    }
    $worker->processItem($item);
  }

  /**
   * Starts capturing messages logged to the 'trash' channel.
   *
   * Captured messages are collected in self::$trashLogRecords.
   */
  private function captureTrashLog(): void {
    $sink = function (string $message): void {
      $this->trashLogRecords[] = $message;
    };
    $logger = new class($sink) implements LoggerInterface {
      use LoggerTrait;

      public function __construct(private readonly \Closure $sink) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        ($this->sink)((string) $message);
      }

    };
    $this->container->get('logger.factory')->get('trash')->addLogger($logger);
  }

  /**
   * Runs a CLI trash operation for the test entity type.
   *
   * @param string $operation
   *   Either 'purge' or 'restore'.
   *
   * @return int
   *   The number of entities the operation reports acting on.
   */
  private function runCliOperation(string $operation): int {
    $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    return $this->container->get(TrashCliActions::class)
      ->performOperation($io, $operation, ['trash_test_entity']);
  }

}
