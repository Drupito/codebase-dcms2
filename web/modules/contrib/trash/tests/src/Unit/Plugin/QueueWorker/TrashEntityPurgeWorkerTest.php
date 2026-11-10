<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Unit\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\trash\Plugin\QueueWorker\TrashEntityPurgeWorker;
use Drupal\trash\TrashManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit test for the entity purge worker.
 */
#[CoversClass(TrashEntityPurgeWorker::class)]
#[Group('trash')]
class TrashEntityPurgeWorkerTest extends UnitTestCase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface|MockObject $entityTypeManager;

  /**
   * The trash manager.
   *
   * @var \Drupal\trash\TrashManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TrashManagerInterface|MockObject $trashManager;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerChannelFactoryInterface|MockObject $loggerFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TimeInterface|MockObject $time;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LanguageManagerInterface|MockObject $languageManager;

  /**
   * The trash purge worker under test.
   *
   * @var \Drupal\trash\Plugin\QueueWorker\TrashEntityPurgeWorker
   */
  protected TrashEntityPurgeWorker $worker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $configuration = [];
    $plugin_id = 'trash_entity_purge';
    $plugin_definition = [
      'id' => $plugin_id,
    ];
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->trashManager = $this->createMock(TrashManagerInterface::class);
    $this->trashManager->expects($this->any())
      ->method('getEnabledEntityTypes')
      ->willReturn(['node', 'media']);
    $this->trashManager->expects($this->any())
      ->method('isEntityTypeEnabled')
      ->willReturn(TRUE);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->worker = new TrashEntityPurgeWorker($configuration, $plugin_id, $plugin_definition, $this->entityTypeManager, $this->trashManager, $this->loggerFactory, $this->time, $this->languageManager);
    $this->worker->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Tests TrashEntityPurgeWorker::processItem().
   */
  public function testProcessItem() {
    // Nothing ends up purged in this scenario, so no success message may be
    // logged.
    $this->loggerFactory->expects($this->never())
      ->method('get');

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('accessCheck')
      ->with(FALSE)
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('addMetaData')
      ->with('trash', 'inactive')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('condition')
      ->with('nid', ['23', '1337'], 'IN')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('execute')
      ->willReturn(['23', '1337']);

    $nodeStorage = $this->createMock(ContentEntityStorageInterface::class);
    $nodeDefinition = $this->createMock(EntityTypeInterface::class);
    $nodeDefinition->expects($this->once())
      ->method('getKey')
      ->with('id')
      ->willReturn('nid');
    $nodeStorage->expects($this->once())
      ->method('getQuery')
      ->willReturn($query);
    $nodeStorage->expects($this->once())
      ->method('loadMultiple')
      ->with(['23', '1337'])
      ->willReturn([]);
    $nodeStorage->expects($this->never())
      ->method('delete');
    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('node')
      ->willReturn($nodeStorage);
    $this->entityTypeManager->expects($this->once())
      ->method('getDefinition')
      ->with('node')
      ->willReturn($nodeDefinition);

    $this->languageManager->expects($this->once())
      ->method('isMultilingual')
      ->willReturn(FALSE);

    // Run the purge closure for real, against a storage that loads nothing.
    $this->trashManager->expects($this->once())
      ->method('executeInTrashContext')
      ->with('inactive', $this->isInstanceOf(\Closure::class))
      ->willReturnCallback(fn (string $context, callable $callback) => $callback());

    // The payload has no 'cutoff' key, so the legacy fallback applies without
    // consulting the time service.
    $this->time->expects($this->never())
      ->method('getCurrentTime');

    $this->worker->processItem([
      'batch' => ['23', '1337'],
      'entity_type_id' => 'node',
    ]);
  }

  /**
   * Tests that queue items for no-longer-enabled entity types are dropped.
   */
  public function testProcessItemSkipsDisabledEntityType() {
    // The setUp() trash manager pins isEntityTypeEnabled() to TRUE, so build
    // a worker around a fresh mock that reports the entity type as disabled.
    $trash_manager = $this->createMock(TrashManagerInterface::class);
    $trash_manager->expects($this->once())
      ->method('isEntityTypeEnabled')
      ->with('node')
      ->willReturn(FALSE);
    $trash_manager->expects($this->never())
      ->method('executeInTrashContext');

    $this->entityTypeManager->expects($this->never())
      ->method('getStorage');
    $this->entityTypeManager->expects($this->never())
      ->method('getDefinition');
    $this->loggerFactory->expects($this->never())
      ->method('get');

    $worker = new TrashEntityPurgeWorker([], 'trash_entity_purge', ['id' => 'trash_entity_purge'], $this->entityTypeManager, $trash_manager, $this->loggerFactory, $this->time, $this->languageManager);
    $worker->setStringTranslation($this->getStringTranslationStub());

    $worker->processItem([
      'batch' => ['23', '1337'],
      'entity_type_id' => 'node',
    ]);
  }

}
