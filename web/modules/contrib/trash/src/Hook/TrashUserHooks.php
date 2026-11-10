<?php

declare(strict_types=1);

namespace Drupal\trash\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\trash\TrashManagerInterface;

/**
 * User-related hook implementations for Trash.
 */
class TrashUserHooks {

  /**
   * The trash context to restore once user cancellation has been processed.
   */
  protected ?string $previousTrashContext = NULL;

  public function __construct(
    protected TrashManagerInterface $trashManager,
  ) {}

  /**
   * Implements hook_user_cancel().
   */
  #[Hook('user_cancel', order: Order::First)]
  public function userCancelSetIgnoreContext(): void {
    // Set the trash context to 'ignore' so that all subsequent hook_user_cancel
    // implementations can query and update trashed entities.
    $this->previousTrashContext = $this->trashManager->getTrashContext();
    $this->trashManager->setTrashContext('ignore');
  }

  /**
   * Implements hook_user_cancel().
   */
  #[Hook('user_cancel', order: Order::Last)]
  public function userCancelRestoreContext(): void {
    // Restore whatever the trash context was before cancellation started,
    // rather than assuming it was 'active'.
    $this->trashManager->setTrashContext($this->previousTrashContext ?? 'active');
    $this->previousTrashContext = NULL;
  }

}
