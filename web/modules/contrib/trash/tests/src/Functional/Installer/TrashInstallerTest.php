<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional\Installer;

use Drupal\FunctionalTests\Installer\InstallerTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the trash module installs cleanly through the interactive installer.
 *
 * @see https://www.drupal.org/project/trash/issues/3588477
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashInstallerTest extends InstallerTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'trash_install_test';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the install completes without serialization failures.
   */
  public function testInstall(): void {
    static::assertTrue(\Drupal::moduleHandler()->moduleExists('trash'));
  }

}
