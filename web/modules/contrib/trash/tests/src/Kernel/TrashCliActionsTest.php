<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\trash\TrashCliActions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the entity type validation shared by the Trash CLI commands.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashCliActionsTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected bool $installNode = FALSE;

  /**
   * Tests that an invalid entity type ID is rejected with a clear message.
   */
  public function testEntityTypeIdValidation(): void {
    $actions = $this->container->get(TrashCliActions::class);

    // An unknown entity type ID.
    $this->assertFalse($actions->isTrashEnabledType('foo'));
    $message = $actions->invalidEntityTypeMessage('foo');
    $this->assertStringContainsString('foo is not a Trash-enabled entity type', $message);
    $this->assertStringContainsString('trash_test_entity', $message);

    // A missing entity type ID.
    $this->assertFalse($actions->isTrashEnabledType(NULL));
    $this->assertStringContainsString('(none given) is not a Trash-enabled entity type', $actions->invalidEntityTypeMessage(NULL));

    // An installed entity type that is not trash-enabled.
    $this->assertFalse($actions->isTrashEnabledType('user'));
    $this->assertStringContainsString('user is not a Trash-enabled entity type', $actions->invalidEntityTypeMessage('user'));

    // A trash-enabled entity type is accepted.
    $this->assertTrue($actions->isTrashEnabledType('trash_test_entity'));
  }

}
