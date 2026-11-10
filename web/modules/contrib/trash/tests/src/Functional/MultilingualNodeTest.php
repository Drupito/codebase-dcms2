<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\trash\Trash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the basic trash functionality on multilingual nodes.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class MultilingualNodeTest extends BrowserTestBase {

  /**
   * A user with permission to trash content but not restoring.
   */
  protected AccountInterface $webUser;

  /**
   * A user with permission to trash, restore and purge the trash bin.
   */
  protected AccountInterface $adminUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'node', 'trash', 'language'];

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
        'name' => 'Basic Page',
        'display_submitted' => FALSE,
      ]);
    }

    // Additional permissions will be granted if the Content Translation module
    // is enabled by a test.
    $this->webUser = $this->drupalCreateUser([
      'access content',
      'create page content',
      'edit own page content',
      'delete own page content',
      'access trash',
      'purge node translations',
      'restore node translations',
    ]);
    $this->adminUser = $this->drupalCreateUser([
      'access content',
      'create page content',
      'edit own page content',
      'delete own page content',
      'edit any page content',
      'delete any page content',
      'administer nodes',
      'bypass node access',
      'access trash',
      'view deleted entities',
      'purge node entities',
      'restore node entities',
      'administer content types',
      'administer languages',
    ]);
    $this->drupalLogin($this->adminUser);
    $this->addLanguage('sv');
    $this->addLanguage('de');
    $this->drupalPlaceBlock('local_tasks_block', ['id' => 'page_tabs_block']);
    $this->drupalPlaceBlock('local_actions_block', ['id' => 'page_actions_block']);
  }

  /**
   * Data provider for Content Translation module status.
   *
   *  Content Translation really only adds a GUI to perform translation tasks,
   *  as entities may have translations as soon as Language module is enabled.
   *  The difference is whether interactive translation is presented to the user
   *  or not, and Trash should function even if translations were added
   *  programmatically, or Content Translation was disabled after adding some.
   *
   * @return array[]
   *   A data array keyed by language names holding arrays of the corresponding
   *   language code argument.
   */
  public static function contentTranslationEnabledProvider(): array {
    return [
      'With Content Translation' => [TRUE],
      'Without Content Translation' => [FALSE],
    ];
  }

  /**
   * Test moving a node and translations to the trash bin and restoring them.
   */
  #[DataProvider('contentTranslationEnabledProvider')]
  public function testTrashAndRestoreMultiNode(bool $enable_content_translation) {
    if ($enable_content_translation) {
      $this->enableContentTranslation();
    }
    // Login as a regular editor.
    $this->drupalLogin($this->webUser);

    // Create "Basic page" content with translated titles.
    $english_title = $this->randomMachineName(8);
    $swedish_title = $this->randomMachineName(8);
    $german_title = $this->randomMachineName(8);
    $settings = [
      'title' => $english_title,
      'langcode' => 'en',
    ];
    $node = $this->drupalCreateNode($settings);

    // Load the Swedish node add translation form.
    $nid = $node->id();
    $this->addTranslation($node, 'en', 'sv', $swedish_title);

    // Load the German node add translation form.
    $this->addTranslation($node, 'en', 'de', $german_title);

    // Load the English form.
    $this->drupalGet("/node/$nid/edit");
    // Make sure we can move all translations to the trash bin in one go.
    $this->clickLink('Delete');
    $this->assertSession()->addressEquals("/node/$nid/delete");
    $this->assertSession()->pageTextContains('Deleting this content item will move it to the trash.');
    $this->assertSession()->pageTextContains("The following content item translations will be deleted:EnglishSwedishGerman");
    $this->submitForm([], 'Delete all translations');
    // The content has been moved to the trash.
    $this->assertSession()->statusMessageContains('The Basic Page ' . $node->getTitle() . ' has been deleted', 'status');

    // All three translations are soft-deleted in storage.
    $this->assertTranslationDeletionState($nid, ['en' => TRUE, 'sv' => TRUE, 'de' => TRUE]);

    // The regular editor is no longer able to access any translation.
    $this->drupalGet("/node/$nid");
    // Access is 404 because the node "does not exist".
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet("/sv/node/$nid");
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet("/de/node/$nid");
    $this->assertSession()->statusCodeEquals(404);

    // The editor cannot restore any translations because the source is deleted,
    // and they do not have access to restore it.
    $this->drupalGet('/admin/content/trash');
    // Make some positive assertions before checking links do not exist.
    $this->assertSession()->pageTextContains($english_title);
    $this->assertSession()->pageTextContains($swedish_title);
    $this->assertSession()->pageTextContains($german_title);
    // This is a "contains()" check so covers all translations.
    $this->assertSession()->linkByHrefNotExists("/node/$nid/restore");

    // The admin can see all translations in the trash context. The restore and
    // purge local tasks link to the translation being viewed, not to the
    // negotiated content language.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet("/node/$nid", ['query' => ['in_trash' => 1]]);
    $this->assertSession()->pageTextContains($english_title);
    $this->assertSession()->elementExists('css', 'article.is-deleted');
    $this->assertSession()->linkByHrefExists("/node/$nid/restore/en?in_trash=1");
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/en?in_trash=1");
    $this->drupalGet("/sv/node/$nid", ['query' => ['in_trash' => 1]]);
    $this->assertSession()->pageTextContains($swedish_title);
    $this->assertSession()->elementExists('css', 'article.is-deleted');
    $this->assertSession()->linkByHrefExists("/sv/node/$nid/restore/sv?in_trash=1");
    $this->assertSession()->linkByHrefExists("/sv/node/$nid/purge/sv?in_trash=1");
    $this->drupalGet("/de/node/$nid", ['query' => ['in_trash' => 1]]);
    $this->assertSession()->pageTextContains($german_title);
    $this->assertSession()->elementExists('css', 'article.is-deleted');
    $this->assertSession()->linkByHrefExists("/de/node/$nid/restore/de?in_trash=1");
    $this->assertSession()->linkByHrefExists("/de/node/$nid/purge/de?in_trash=1");

    // The admin can restore the main node and translations.
    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->linkByHrefExists("/node/$nid/restore");
    $this->assertSession()->linkByHrefExists("/node/$nid/restore/sv");
    $this->assertSession()->linkByHrefExists("/node/$nid/restore/de");

    // Check that attempting to restore Swedish will also restore English.
    $this->drupalGet("/node/$nid/restore/sv", ['query' => ['in_trash' => 1]]);
    $this->assertSession()->pageTextContains('Restoring the Swedish translation will also restore the English original language.');
    // Same for German.
    $this->drupalGet("/node/$nid/restore/de", ['query' => ['in_trash' => 1]]);
    $this->assertSession()->pageTextContains('Restoring the German translation will also restore the English original language.');
    // Restore only the English original language.
    $this->drupalGet("/node/$nid/restore", ['query' => ['in_trash' => 1]]);
    $this->submitForm([
      'translations[sv]' => FALSE,
      'translations[de]' => FALSE,
    ], 'Confirm');
    $this->assertSession()->statusMessageContains("The content item $english_title has been restored from trash.", 'status');

    // Only English is restored; Swedish and German stay soft-deleted.
    $this->assertTranslationDeletionState($nid, ['en' => FALSE, 'sv' => TRUE, 'de' => TRUE]);

    // The regular editor can now restore translations from the trashcan page.
    $this->drupalLogin($this->webUser);
    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->linkByHrefExists("/node/$nid/restore/sv");
    $this->assertSession()->linkByHrefExists("/node/$nid/restore/de");

    if ($enable_content_translation) {
      // Also verify they can do it from the translation overview page.
      $this->drupalGet("/node/$nid/translations");
      $this->assertSession()->linkByHrefExists("/node/$nid/restore/sv");
      $this->assertSession()->linkByHrefExists("/node/$nid/restore/de");
      $this->assertSession()->pageTextContains("English (Original language) $english_title Published");
      $this->assertSession()->pageTextContains("Swedish $swedish_title Deleted");
      $this->assertSession()->pageTextContains("German $german_title Deleted");
    }

    // Restore the German translation.
    $this->drupalGet("/node/$nid/restore/de");
    $this->submitForm([], 'Confirm');
    $this->assertSession()->statusMessageContains("The content item $german_title has been restored from trash.", 'status');

    // German is now restored too; only Swedish stays soft-deleted.
    $this->assertTranslationDeletionState($nid, ['en' => FALSE, 'sv' => TRUE, 'de' => FALSE]);

    // The regular editor can again see the German translation.
    $this->drupalGet("/de/node/$nid");
    $this->assertSession()->statusCodeEquals(200);

    // Delete the English and German translations again.
    $this->drupalGet("node/$nid/delete");
    $this->submitForm([], 'Delete all translations');
    $this->assertSession()->statusMessageContains("The Basic Page $english_title has been deleted.", 'status');

    // The admin can restore a translation and the original language at once.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet("/node/$nid/restore/sv", ['query' => ['in_trash' => 1]]);

    $this->assertSession()->pageTextContains('Restoring the Swedish translation will also restore the English original language.');
    $this->submitForm([], 'Confirm');
    $this->assertSession()->statusMessageContains("The content item $swedish_title (Swedish) and the $english_title (English) original language have been restored from trash.", 'status');

    // English and Swedish are restored together; German remains soft-deleted.
    $this->assertTranslationDeletionState($nid, ['en' => FALSE, 'sv' => FALSE, 'de' => TRUE]);

    // Switch back to the editor.
    $this->drupalLogin($this->webUser);
    // Verify English and Swedish are visible and German is still inaccessible.
    $this->drupalGet("/node/$nid");
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet("/sv/node/$nid");
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet("/de/node/$nid");
    $this->assertSession()->statusCodeEquals(403);

    // Switch back to the Admin and delete all translations.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet("/node/$nid/delete");
    $this->submitForm([], 'Delete all translations');
    // Restore the English original language and only the German translation.
    $this->drupalGet("/node/$nid/restore", ['query' => ['in_trash' => 1]]);

    $this->submitForm([
      'translations[sv]' => FALSE,
      'translations[de]' => TRUE,
    ], 'Confirm');

    // Verify the Swedish translation is still deleted.
    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->linkByHrefExists("/node/$nid/restore/sv");

    // English and German were restored; only Swedish is still soft-deleted.
    $this->assertTranslationDeletionState($nid, ['en' => FALSE, 'sv' => TRUE, 'de' => FALSE]);
  }

  /**
   * Test moving a node and translations to the trash bin and purging them.
   */
  #[DataProvider('contentTranslationEnabledProvider')]
  public function testPurgingMultiNode(bool $enable_content_translation) {
    if ($enable_content_translation) {
      $this->enableContentTranslation();
    }
    // Login as a regular editor.
    $this->drupalLogin($this->webUser);

    // Create "Basic page" content with translated titles.
    $english_title = $this->randomMachineName(8);
    $swedish_title = $this->randomMachineName(8);
    $german_title = $this->randomMachineName(8);
    $settings = [
      'title' => $english_title,
      'langcode' => 'en',
    ];
    $node = $this->drupalCreateNode($settings);

    // Load the Swedish node add translation form.
    $nid = $node->id();
    $this->addTranslation($node, 'en', 'sv', $swedish_title);

    // Load the German node add translation form.
    $this->addTranslation($node, 'en', 'de', $german_title);

    // Delete the node.
    $this->drupalGet("/node/$nid/delete");
    $this->submitForm([], 'Delete all translations');

    // The editor may purge translations but not the original.
    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->pageTextContains($english_title);
    $this->assertSession()->pageTextContains($swedish_title);
    $this->assertSession()->pageTextContains($german_title);
    $base_path = base_path();
    $this->assertSession()->linkByHrefNotExistsExact("{$base_path}node/$nid/purge?destination={$base_path}admin/content/trash");
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/sv");
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/de");
    if ($enable_content_translation) {
      // The translations overview is disabled because the original is deleted.
      $this->drupalGet("/node/$nid/translations");
      $this->assertSession()->statusCodeEquals(404);
    }

    // The admin may purge all the translations.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->linkByHrefExists("/node/$nid/purge");
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/sv");
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/de");

    // Restore the English original language.
    $this->assertSession()->linkByHrefExists("/node/$nid/restore");
    $this->drupalGet("/node/$nid/restore", ['query' => ['in_trash' => 1]]);
    $this->submitForm([
      'translations[sv]' => FALSE,
      'translations[de]' => FALSE,
    ], 'Confirm');

    // The editor may now purge the translations.
    $this->drupalLogin($this->webUser);
    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/sv");
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/de");
    if ($enable_content_translation) {
      // Also verify they can do it from the translation overview page.
      $this->drupalGet("/node/$nid/translations");
      $this->assertSession()->linkByHrefExists("/node/$nid/purge/sv");
      $this->assertSession()->linkByHrefExists("/node/$nid/purge/de");
      $this->assertSession()->pageTextContains("Swedish $swedish_title Deleted");
      $this->assertSession()->pageTextContains("German $german_title Deleted");
    }

    // Purge the Swedish translation.
    $this->drupalGet("/node/$nid/purge/sv", ['query' => ['in_trash' => 1]]);
    $this->assertSession()->pageTextContains("Are you sure you want to permanently delete the Swedish translation of the content item $swedish_title?");
    $this->submitForm([], 'Delete Swedish translation');
    // Check that the German translation was not removed.
    $this->assertSession()->linkByHrefExists("/node/$nid/purge/de");
    // Check that the Swedish translation can be re-added via the GUI.
    if ($enable_content_translation) {
      // First, verify the add-link is also on the translation overview page.
      $this->drupalGet("/node/$nid/translations");
      $this->assertSession()->linkByHrefExists("/node/$nid/purge/de");
      $this->assertSession()->pageTextContains("Swedish N/A Not translated");
      $this->assertSession()->pageTextContains("German $german_title Deleted");
      $this->addTranslation($node, 'en', 'sv', $swedish_title);
    }
  }

  /**
   * Asserts the soft-deleted state of each translation directly in storage.
   *
   * @param int|string $nid
   *   The node ID.
   * @param array<string, bool> $expected
   *   Whether each langcode is expected to be soft-deleted, keyed by langcode.
   */
  protected function assertTranslationDeletionState(int|string $nid, array $expected): void {
    $trash_manager = \Drupal::service('trash.manager');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $node = $trash_manager->executeInTrashContext('ignore', fn () => $storage->load($nid));
    $this->assertInstanceOf(NodeInterface::class, $node);
    foreach ($expected as $langcode => $is_deleted) {
      $this->assertTrue($node->hasTranslation($langcode), "Translation '$langcode' exists.");
      $this->assertSame($is_deleted, Trash::entityIsDeleted($node->getTranslation($langcode)), "Translation '$langcode' soft-deleted state.");
    }
  }

  /**
   * Adds a language.
   *
   * @param string $langcode
   *   The language code of the language to add.
   */
  protected function addLanguage($langcode) {
    $edit = ['predefined_langcode' => $langcode];
    $this->drupalGet('admin/config/regional/language/add');
    $this->submitForm($edit, 'Add language');
    $this->container->get('language_manager')->reset();
    $this->assertNotEmpty(\Drupal::languageManager()->getLanguage($langcode), "Language $langcode added.");
  }

  /**
   * Add a translation to a node.
   *
   * This only uses the UI if Content Translation is enabled and otherwise
   * creates it programmatically.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to add a translation to.
   * @param string $source_langcode
   *   The source langcode.
   * @param string $langcode
   *   The new translation langcode.
   * @param string $title
   *   The new translation title.
   */
  protected function addTranslation(NodeInterface $node, string $source_langcode, string $langcode, string $title) {
    if ($this->container->get('module_handler')->moduleExists('content_translation')) {
      $nid = $node->id();
      $this->drupalGet("/node/$nid/translations/add/$source_langcode/$langcode");
      $edit = [
        'Title' => $title,
      ];
      $this->submitForm($edit, 'Save (this translation)');
      $this->assertSession()->pageTextContains("$title has been updated");
      return;
    }
    $node = $this->drupalGetNodeByTitle($node->getUntranslated()->getTitle());
    $this->assertFalse($node->hasTranslation($langcode), 'Translation does not already exist');
    $translation = $node->addTranslation($langcode, [
      'title' => $title,
    ]);
    $translation->save();
    $this->assertSame($title, $node->getTranslation($langcode)
      ->getTitle(), 'Translation exists');
  }

  /**
   * Enable and configure Content Translation module.
   */
  protected function enableContentTranslation() {
    $success = $this->container->get('module_installer')
      ->install(['content_translation'], TRUE);
    $this->assertTrue($success, 'Enabled Content Translation');

    ContentLanguageSettings::loadByEntityTypeBundle('node', 'page')
      ->setDefaultLangcode('en')
      ->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)
      ->save();

    $editor_roles = $this->webUser->getRoles(TRUE);
    /** @var \Drupal\user\RoleInterface $role */
    $role = \Drupal::entityTypeManager()
      ->getStorage('user_role')
      ->load(reset($editor_roles));
    $role->grantPermission('translate editable entities');
    $role->grantPermission('update content translations');
    $role->save();

    $admin_roles = $this->adminUser->getRoles(TRUE);
    /** @var \Drupal\user\RoleInterface $role */
    $role = \Drupal::entityTypeManager()
      ->getStorage('user_role')
      ->load(reset($admin_roles));
    $role->grantPermission('administer content translation');
    $role->grantPermission('delete content translations');
    $role->grantPermission('translate editable entities');
    $role->save();

    /** @var \Drupal\user\UserStorageInterface $user_storage */
    $user_storage = $this->container->get('entity_type.manager')
      ->getStorage('user');
    $user_storage->resetCache([$this->adminUser->id(), $this->webUser->id()]);

    $this->rebuildAll();
  }

}
