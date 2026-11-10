<?php

declare(strict_types=1);

namespace Drupal\trash\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Restores trashed entities via the core `dr` CLI.
 */
#[AsCommand(
  name: 'trash:restore',
  description: 'Restores trashed entities.',
  aliases: ['tr'],
)]
final class TrashRestoreCommand extends TrashOperationCommandBase {

  /**
   * {@inheritdoc}
   */
  protected function getOperation(): string {
    return 'restore';
  }

}
