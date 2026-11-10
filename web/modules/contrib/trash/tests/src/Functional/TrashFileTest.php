<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional;

use Drupal\file\Entity\File;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the basic trash functionality on files.
 */
#[Group('trash')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class TrashFileTest extends BrowserTestBase {

  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['trash', 'file', 'path_alias', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to trash, restore and purge the trash bin.
   */
  protected UserInterface $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure the user is not user id 1.
    $this->drupalCreateUser();
    $this->adminUser = $this->drupalCreateUser([
      'access trash',
      'view deleted entities',
      'administer trash',
      'access files overview',
      'delete any file',
    ]);
  }

  /**
   * Test moving a file to the trash bin, restoring it, and purging it.
   */
  public function testTrashRestorePurgeFile(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/content/trash');
    $edit = [
      'enabled_entity_types[file][enabled]' => TRUE,
      'enabled_entity_types[path_alias][enabled]' => TRUE,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    // Neither entity type has a canonical page, so they get no local tasks.
    $definitions = \Drupal::service('plugin.manager.menu.local_task')->getDefinitions();
    $this->assertArrayNotHasKey('trash.entity_restore:file', $definitions);
    $this->assertArrayNotHasKey('trash.entity_purge:file', $definitions);
    $this->assertArrayNotHasKey('trash.entity_restore:path_alias', $definitions);
    $this->assertArrayNotHasKey('trash.entity_purge:path_alias', $definitions);

    $trash_file_user = $this->createUser([
      'access files overview',
      'delete own files',
      'access trash',
      'purge file entities',
      'restore file entities',
    ]);
    $this->drupalLogin($trash_file_user);

    // Create the test file.
    $text_files = $this->getTestFiles('text');
    $file = File::create([
      'uri' => $text_files[0]->uri,
      'uid' => $trash_file_user->id(),
    ]);
    $file->save();

    $fid = $file->id();
    $filename = $file->getFilename();
    $file_url = $file->createFileUrl();

    // 1. Trash the file.
    $this->drupalGet('admin/content/files', ['query' => ['order' => 'filename', 'sort' => 'asc']]);
    $this->clickLink('Delete');
    $this->assertSession()->addressMatches("#file/$fid/delete$#");
    $this->assertSession()->pageTextContains("Are you sure you want to delete the file $filename?");
    $this->assertSession()->pageTextContains('Deleting this file will move it to the trash. You can restore it from the trash at a later date if necessary.');
    $this->assertSession()->buttonExists('Delete')->press();

    $this->assertSession()->statusMessageContains("The file $filename has been deleted", 'status');

    // Verify file is in trash.
    $this->drupalGet('admin/content/trash/file');
    $this->assertSession()->pageTextContains($filename);
    $this->assertSession()->linkExists('Restore');
    $this->assertSession()->linkExists('Purge');

    // 2. Restore the file.
    $this->clickLink('Restore');
    $this->assertSession()->pageTextContains("Are you sure you want to restore the file $filename?");
    $this->submitForm([], 'Confirm');

    $this->assertSession()->statusMessageContains("The file $filename has been restored from trash.", 'status');
    // The status message should link to the public file.
    $this->assertSession()->linkByHrefExists($file_url, 0, $filename);

    // Verify file is no longer in trash.
    $this->drupalGet('admin/content/trash/file');
    $this->assertSession()->pageTextNotContains($filename);
    $this->assertSession()->pageTextContains('There are no deleted files.');

    // 3. Trash it again to test purge.
    $this->drupalGet('admin/content/files', ['query' => ['order' => 'filename', 'sort' => 'asc']]);
    $this->clickLink('Delete');
    $this->assertSession()->pageTextContains("Are you sure you want to delete the file $filename?");
    $this->assertSession()->addressMatches("#file/$fid/delete$#");
    $this->assertSession()->buttonExists('Delete')->press();

    // 4. Purge the file.
    $this->drupalGet('admin/content/trash/file');
    $this->clickLink('Purge');
    $this->assertSession()->pageTextContains("Are you sure you want to permanently delete the file $filename?");
    $this->assertSession()->pageTextContains('This action cannot be undone.');
    $this->submitForm([], 'Confirm');

    $this->assertSession()->statusMessageContains("The file $filename has been permanently deleted.", 'status');

    // Verify trash is empty.
    $this->drupalGet('admin/content/trash/file');
    $this->assertSession()->pageTextNotContains($filename);
    $this->assertSession()->pageTextContains('There are no deleted files.');

    // The purge removed the file entity from storage, not just from the
    // listing. An entity query in the 'ignore' trash context still counts a
    // merely-trashed file, so a count of zero proves the row is truly gone.
    $remaining = (int) \Drupal::entityQuery('file')
      ->accessCheck(FALSE)
      ->addMetaData('trash', 'ignore')
      ->condition('fid', $fid)
      ->count()
      ->execute();
    $this->assertSame(0, $remaining, 'The purged file entity no longer exists in storage.');
  }

}
