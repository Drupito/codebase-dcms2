<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\workspaces\Functional\WorkspaceTestUtilities;
use Drupal\workspaces\Entity\Workspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the trash ignore functionality.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
final class TrashWorkspaceIgnoreTest extends BrowserTestBase {

  use ContentTypeCreationTrait;
  use WorkspaceTestUtilities;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'block',
    'trash',
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

    if ($this->profile != 'standard') {
      $this->drupalCreateContentType([
        'type' => 'page',
        'name' => 'Basic Page',
        'display_submitted' => FALSE,
      ]);
    }
    $permissions = [
      'administer nodes',
      'administer workspaces',
      'create article content',
      'edit any article content',
      'view deleted entities',
    ];

    // Create a content type.
    $this->drupalCreateContentType([
      'name' => 'article',
      'type' => 'article',
    ]);
    $this->drupalLogin($this->drupalCreateUser($permissions));
    $this->setupWorkspaceSwitcherBlock();
    if (!Workspace::load('stage')) {
      $this->createWorkspaceThroughUi('Stage', 'stage');
    }
  }

  /**
   * Test that ignoring works in workspaces.
   */
  public function testWorkspaceIgnore(): void {
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Test article',
    ]);
    $nid = $node->id();

    $stage = Workspace::load('stage');
    $this->switchToWorkspace($stage);

    // Delete the node only in the workspace, making it register changes.
    $node->delete();

    $this->drupalGet("/node/{$nid}");
    $this->assertSession()->statusCodeEquals(404);

    // Explicitly switch to the inactive trash context.
    $this->drupalGet("/node/{$nid}", ['query' => ['in_trash' => 1]]);
    $this->assertSession()->statusCodeEquals(200);

    // The storage classes cached the loaded entity ids/revisions, verify that
    // Workspaces' EntityOperations::entityPreload() does not load them when the
    // trash context implicitly changes back to active. This is only an issue in
    // D11 due to new storage caching and workspace entity preloading.
    $this->drupalGet("/node/{$nid}");
    $this->assertSession()->statusCodeEquals(404);
  }

}
