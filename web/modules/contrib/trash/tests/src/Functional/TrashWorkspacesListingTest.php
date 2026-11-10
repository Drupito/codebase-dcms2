<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional;

use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\workspaces\Functional\WorkspaceTestUtilities;
use Drupal\trash\Trash;
use Drupal\workspaces\Entity\Workspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the trash overview for entities trashed inside a workspace.
 *
 * Covers both listing paths: the dynamically built View that entity types with
 * a views_data handler use (node), and the fallback table used by those without
 * one (path_alias).
 *
 * @see \Drupal\trash\Controller\TrashController::renderView()
 * @see \Drupal\trash\Controller\TrashController::renderFallbackTable()
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashWorkspacesListingTest extends BrowserTestBase {

  use WorkspaceTestUtilities;

  /**
   * The trash overview path for path aliases.
   *
   * 'node' is the default entity type of the overview, so path aliases are only
   * reachable through the entity-type specific route.
   */
  private const ALIAS_OVERVIEW = '/admin/content/trash/path_alias';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'node',
    'path',
    'path_alias',
    'trash',
    'views',
    'workspaces',
    'workspaces_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    $this->config('trash.settings')
      ->set('enabled_entity_types', ['node' => [], 'path_alias' => []])
      ->save();
    $this->rebuildAll();

    // Deliberately no 'purge path_alias entities', so the listing's operations
    // can be checked against a user who may restore but not purge.
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'access trash',
      'administer nodes',
      'administer url aliases',
      'administer workspaces',
      'bypass node access',
      'restore path_alias entities',
      'view any workspace',
    ]));

    $this->setupWorkspaceSwitcherBlock();
    $this->createWorkspaceThroughUi('Stage', 'stage');
  }

  /**
   * The fallback table lists aliases trashed inside a workspace.
   */
  public function testWorkspaceTrashedEntityIsListed(): void {
    $live_made = $this->createAlias('/live-made');

    $this->drupalGet(self::ALIAS_OVERVIEW);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('There are no deleted URL aliases.');

    $this->switchToWorkspace(Workspace::load('stage'));

    // An entity created inside a workspace keeps an unpublished default
    // revision until the workspace is published, so cover it alongside one
    // that already existed in Live.
    $stage_made = $this->createAlias('/stage-made');

    // Trashing inside the workspace writes 'deleted' onto a pending workspace
    // revision, leaving the live default revision untouched.
    $live_made->delete();
    $stage_made->delete();

    $this->drupalGet(self::ALIAS_OVERVIEW);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('There are no deleted URL aliases.');

    $row = $this->assertSession()->elementExists('xpath', '//table//tr[contains(., "/live-made")]');
    $this->assertSession()->elementExists('xpath', '//table//tr[contains(., "/stage-made")]');

    // The 'Deleted' column shows the workspace revision's own timestamp, not an
    // empty cell inherited from the untouched live revision.
    $this->assertStringContainsString($this->getDeletedDate($live_made->id()), $row->getText());

    // Operations are built from the trashed revision and respect permissions.
    $this->assertNotNull($row->findLink('Restore'));
    $this->assertNull($row->findLink('Purge'));

    // The listing query is sorted through tableSort(). Re-sorting must not drop
    // rows or change which revision each row reports.
    $this->clickLink('Deleted');
    $this->assertSession()->statusCodeEquals(200);
    $resorted = $this->assertSession()->elementExists('xpath', '//table//tr[contains(., "/live-made")]');
    $this->assertSession()->elementExists('xpath', '//table//tr[contains(., "/stage-made")]');
    $this->assertStringContainsString($this->getDeletedDate($live_made->id()), $resorted->getText());

    // Live still has the alias it owns, so its trash stays empty.
    $this->switchToLive();
    $this->drupalGet(self::ALIAS_OVERVIEW);
    $this->assertSession()->pageTextContains('There are no deleted URL aliases.');
  }

  /**
   * The Views listing lists a node trashed inside a workspace.
   */
  public function testWorkspaceTrashedNodeIsListedViaViews(): void {
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Trashed in stage']);

    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Trashed in stage');

    $this->switchToWorkspace(Workspace::load('stage'));
    $node->delete();

    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Trashed in stage');

    // Live keeps the node, so its trash stays empty.
    $this->switchToLive();
    $this->drupalGet('/admin/content/trash');
    $this->assertSession()->pageTextNotContains('Trashed in stage');
  }

  /**
   * An alias trashed in Live is listed in Live and inside a workspace.
   */
  public function testLiveTrashedEntityIsListedInBoth(): void {
    $alias = $this->createAlias('/live-trashed');
    $alias->delete();

    $this->drupalGet(self::ALIAS_OVERVIEW);
    $this->assertSession()->pageTextContains('/live-trashed');

    // The workspace has no revision of its own for this alias, so it falls back
    // to the trashed live revision.
    $this->switchToWorkspace(Workspace::load('stage'));
    $this->drupalGet(self::ALIAS_OVERVIEW);
    $this->assertSession()->pageTextContains('/live-trashed');
  }

  /**
   * Restoring from the listing inside a workspace leaves Live untouched.
   */
  public function testRestoreFromWorkspaceListing(): void {
    $alias = $this->createAlias('/restore-me');
    $alias_id = $alias->id();

    $this->switchToWorkspace(Workspace::load('stage'));
    $alias->delete();

    $this->drupalGet(self::ALIAS_OVERVIEW);
    $this->clickLink('Restore');
    $this->submitForm([], 'Confirm');

    $this->drupalGet(self::ALIAS_OVERVIEW);
    $this->assertSession()->pageTextContains('There are no deleted URL aliases.');

    $restored = PathAlias::load($alias_id);
    $this->assertNotNull($restored, 'The alias loads again inside the workspace.');
    $this->assertFalse(Trash::entityIsDeleted($restored));

    $this->switchToLive();
    $live = PathAlias::load($alias_id);
    $this->assertNotNull($live);
    $this->assertFalse(Trash::entityIsDeleted($live));
  }

  /**
   * Creates a saved path alias pointing at the front page.
   */
  private function createAlias(string $alias): PathAlias {
    $entity = PathAlias::create([
      'path' => '/user/1',
      'alias' => $alias,
    ]);
    $entity->save();

    return $entity;
  }

  /**
   * Returns the formatted deletion date the listing should render for an alias.
   */
  private function getDeletedDate(int|string $id): string {
    $trashed = \Drupal::service('trash.manager')
      ->executeInTrashContext('ignore', fn () => PathAlias::load($id));

    return \Drupal::service('date.formatter')->format($trashed->get('deleted')->value, 'short');
  }

}
