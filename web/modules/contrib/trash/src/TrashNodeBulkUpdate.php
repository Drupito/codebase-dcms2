<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\node\NodeBulkUpdate;

/**
 * Decorates the node bulk update service to ignore the trash context.
 *
 * The hook_user_cancel implementations set the trash context to 'ignore' for
 * the query phase, but batch operations run in separate requests where that
 * context is lost. This decorator ensures the batch callbacks also run in the
 * 'ignore' trash context.
 */
class TrashNodeBulkUpdate extends NodeBulkUpdate {

  public function __construct(
    protected NodeBulkUpdate $inner,
    EntityTypeManagerInterface $entityTypeManager,
    MessengerInterface $messenger,
  ) {
    parent::__construct($entityTypeManager, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function batchProcess(array $nodes, array $updates, ?string $langcode, bool $load, bool $revisions, array|\ArrayAccess &$context): void {
    \Drupal::service(TrashManagerInterface::class)->executeInTrashContext('ignore', function () use ($nodes, $updates, $langcode, $load, $revisions, &$context) {
      parent::batchProcess($nodes, $updates, $langcode, $load, $revisions, $context);
    });
  }

}
