<?php

declare(strict_types=1);

namespace Drupal\trash\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Purges trashed entities via the core `dr` CLI.
 */
#[AsCommand(
  name: 'trash:purge',
  description: 'Purges trashed entities.',
  aliases: ['tp'],
)]
final class TrashPurgeCommand extends TrashOperationCommandBase {

  /**
   * {@inheritdoc}
   */
  protected function getOperation(): string {
    return 'purge';
  }

}
