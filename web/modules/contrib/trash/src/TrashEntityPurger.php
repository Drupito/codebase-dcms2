<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;

/**
 * Purges content automatically when a given time has passed.
 */
class TrashEntityPurger {

  use DependencySerializationTrait;

  /**
   * The name of the queue that holds local items to be purged.
   *
   * @see \Drupal\trash\Plugin\QueueWorker\TrashEntityPurgeWorker
   */
  const PURGE_QUEUE_NAME = 'trash_entity_purge';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TrashManagerInterface $trashManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected QueueFactory $queueFactory,
    protected Settings $settings,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Populates the purge queue with items that have to be deleted.
   */
  public function populatePurgeQueue(string $entity_type_id): void {
    $now = $this->time->getCurrentTime();
    $config = $this->configFactory->get('trash.settings');
    $datetime = strtotime(sprintf('-%s', $config->get('auto_purge.after')), $now);

    if ($datetime === FALSE) {
      $this->loggerFactory->get('trash')->error("The 'auto_purge.after' setting (@value) could not be parsed. Auto-purge is skipped for @entity_type_id until it is fixed.", [
        '@value' => $config->get('auto_purge.after'),
        '@entity_type_id' => $entity_type_id,
      ]);
      return;
    }

    $queue = $this->queueFactory->get(self::PURGE_QUEUE_NAME);

    $ids = $this->entityTypeManager
      ->getStorage($entity_type_id)
      ->getQuery()
      ->accessCheck(FALSE)
      ->addMetaData('trash', 'inactive')
      ->condition('deleted', $datetime, '<')
      ->execute();

    // Bail out early if there are no items to purge.
    if (empty($ids)) {
      return;
    }

    $chunks = array_chunk($ids, $this->settings->get('entity_update_batch_size', 50));
    foreach ($chunks as $chunk) {
      $queue->createItem([
        'batch' => $chunk,
        'entity_type_id' => $entity_type_id,
        'cutoff' => $datetime,
      ]);
    }
  }

  /**
   * Cron handler that will auto-purge content if enabled.
   */
  public function cronPurge(): void {
    $config = $this->configFactory->get('trash.settings');

    if ($config->get('auto_purge.enabled')) {
      // The worker is time-boxed per cron run, so a large backlog can take
      // several runs to drain. Don't re-scan while items are still queued, or
      // every run would pile duplicate items for the same entities onto the
      // shared queue. Once it drains, the next run resumes populating it.
      if ($this->queueFactory->get(self::PURGE_QUEUE_NAME)->numberOfItems() > 0) {
        return;
      }
      foreach ($this->trashManager->getEnabledEntityTypes() as $entityTypeId) {
        $this->populatePurgeQueue($entityTypeId);
      }
    }
  }

}
