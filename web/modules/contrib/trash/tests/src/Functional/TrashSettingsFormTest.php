<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\trash\Form\TrashSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Test the trash settings form.
 */
#[CoversClass(TrashSettingsForm::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashSettingsFormTest extends BrowserTestBase {

  /**
   * A user with permission to configure the trash module.
   */
  protected AccountInterface $adminUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'media', 'node', 'trash'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create Basic page node type.
    if ($this->profile != 'standard') {
      $this->drupalCreateContentType([
        'type' => 'page',
        'name' => 'Basic page',
        'display_submitted' => FALSE,
      ]);
    }

    $this->adminUser = $this->drupalCreateUser([
      'administer trash',
    ]);
    $this->drupalPlaceBlock('local_tasks_block', ['id' => 'page_tabs_block']);
    $this->drupalPlaceBlock('local_actions_block', ['id' => 'page_actions_block']);
  }

  /**
   * Test storing trash settings.
   */
  public function testStoringSettings() {
    // Login as the privileged user.
    $this->drupalLogin($this->adminUser);

    // Load the settings form.
    $this->drupalGet('admin/config/content/trash');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->titleEquals('Trash Settings | Drupal');

    $this->assertSession()->checkboxChecked('enabled_entity_types[node][enabled]');
    $this->assertSession()->checkboxNotChecked('enabled_entity_types[media][enabled]');

    $this->assertSession()->checkboxNotChecked('auto_purge[enabled]');
    $this->assertSession()->fieldValueEquals('auto_purge[after]', '30 days');

    $edit = [
      'enabled_entity_types[node][enabled]' => FALSE,
      'enabled_entity_types[media][enabled]' => TRUE,
      'auto_purge[enabled]' => TRUE,
      'auto_purge[after]' => '45 days',
    ];
    $this->submitForm($edit, 'Save configuration');

    $this->assertSession()->checkboxNotChecked('enabled_entity_types[node][enabled]');
    $this->assertSession()->checkboxChecked('enabled_entity_types[media][enabled]');
    $this->assertSession()->checkboxChecked('auto_purge[enabled]');
    $this->assertSession()->fieldValueEquals('auto_purge[after]', '45 days');

    $this->assertTrashSettingsValidatable();
  }

  /**
   * Test storing trash settings.
   */
  public function testFailingValidatingSettings() {
    // Login as the privileged user.
    $this->drupalLogin($this->adminUser);

    // Load the settings form.
    $this->drupalGet('admin/config/content/trash');

    $edit = [
      'auto_purge[after]' => '1 month and 3 hours',
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusMessageContains("The time period '1 month and 3 hours' is not valid.", 'error');

    $edit = [
      'auto_purge[after]' => 'Last saturday',
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusMessageContains("The time period 'Last saturday' is not valid.", 'error');

    $edit = [
      'auto_purge[after]' => 'Next saturday',
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusMessageContains("The time period 'Next saturday' is not valid.", 'error');

    $edit = [
      'auto_purge[after]' => '3 days, 1 hour',
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusMessageContains('The configuration options have been saved.', 'status');
  }

  /**
   * Test that the auto-purge message appears on the trash overview page.
   *
   * Verifies that when auto_purge is enabled, a message is shown on the trash
   * overview page indicating when items will be permanently deleted. When
   * auto_purge is disabled, no such message should appear.
   */
  public function testAutoPurgeMessageOnOverviewPage(): void {
    $this->drupalLogin($this->adminUser);

    // By default, auto_purge is disabled; no message should appear.
    $this->drupalGet('admin/content/trash');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Items in the trash will be deleted forever after');

    // Enable auto_purge with a 30-day period via the settings form.
    $this->drupalGet('admin/config/content/trash');
    $this->submitForm([
      'auto_purge[enabled]' => TRUE,
      'auto_purge[after]' => '30 days',
    ], 'Save configuration');
    $this->assertSession()->statusMessageContains('The configuration options have been saved.', 'status');

    // The trash overview page should now display the auto-purge message.
    $this->drupalGet('admin/content/trash');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Items in the trash will be deleted forever after');

    // Disable auto_purge; the message should no longer appear.
    $this->drupalGet('admin/config/content/trash');
    $this->submitForm(['auto_purge[enabled]' => FALSE], 'Save configuration');
    $this->drupalGet('admin/content/trash');
    $this->assertSession()->pageTextNotContains('Items in the trash will be deleted forever after');

    // Disabling auto-purge drops 'auto_purge.after' from the saved config, so
    // this is the path most likely to trip the schema.
    $this->assertTrashSettingsValidatable();
  }

  /**
   * Asserts that the saved trash settings pass config validation.
   */
  protected function assertTrashSettingsValidatable(): void {
    $this->refreshVariables();
    $typed_config = $this->container->get('config.typed');
    $data = $this->config('trash.settings')->getRawData();

    // The schema marks trash.settings as FullyValidatable, so every key must be
    // present and non-NULL unless it says 'nullable: true'.
    $violations = $typed_config->createFromNameAndData('trash.settings', $data)->validate();
    $this->assertCount(0, $violations, (string) $violations);

    // Check that the constraint is really in effect. Contrib tests don't
    // validate constraints on config save, so without this the assertion above
    // would pass no matter what the schema says.
    // @see \Drupal\Core\Config\Development\ConfigSchemaChecker
    $violations = $typed_config->createFromNameAndData('trash.settings', ['compact_overview' => NULL] + $data)->validate();
    $this->assertGreaterThan(0, $violations->count(), "A NULL 'compact_overview' passed validation, so trash.settings is not fully validatable.");
  }

}
