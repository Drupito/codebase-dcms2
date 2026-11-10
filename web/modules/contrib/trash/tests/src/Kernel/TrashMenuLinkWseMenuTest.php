<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\trash\Menu\TrashMenuLinkManager;
use Drupal\workspaces\Entity\Workspace;
use Drupal\workspaces\WorkspaceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the menu link trash tests with wse_menu installed.
 */
#[CoversClass(TrashMenuLinkManager::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashMenuLinkWseMenuTest extends TrashMenuLinkTest {

  use WorkspaceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'menu_ui',
    'options',
    'views',
    'workspaces',
    'workspaces_ui',
    'wse',
    'wse_menu',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workspaceManager = \Drupal::service('workspaces.manager');

    $this->installConfig(['system', 'views', 'wse']);
    $this->installSchema('workspaces', ['workspace_association']);
    if (isset(workspaces_schema()['workspace_association_revision'])) {
      $this->installSchema('workspaces', ['workspace_association_revision']);
    }
    $this->installSchema('wse', ['workspace_published_revisions']);
    // Install whatever tables wse_menu declares, without depending on how it
    // stores workspace menu trees.
    \Drupal::moduleHandler()->loadInclude('wse_menu', 'install');
    if (function_exists('wse_menu_schema')) {
      $this->installSchema('wse_menu', array_keys(wse_menu_schema()));
    }
    $this->installEntitySchema('workspace');

    $this->setCurrentUser($this->createUser([
      'administer workspaces',
      'administer menu',
      'view any workspace',
    ]));

    // Build the Live menu tree before any workspace reads it.
    \Drupal::service('plugin.manager.menu.link')->rebuild();

    $this->workspaces['stage'] = Workspace::create(['label' => 'Stage']);
    $this->workspaces['stage']->save();
  }

  /**
   * Loads a menu tree inside a workspace, through the public menu tree API.
   */
  protected function loadMenuTreeInWorkspace(WorkspaceInterface $workspace, string $menu_name): array {
    return $this->workspaceManager->executeInWorkspace($workspace->id(), fn (): array => $this->loadMenuTree($menu_name));
  }

  /**
   * Tests that trashing a link in a workspace leaves the Live tree alone.
   *
   * Covers the trash itself and a following Live rebuild, which must not bring
   * the trashed link back into the workspace tree.
   *
   * @see \Drupal\Tests\wse_menu\Kernel\WseMenuTreeRebuildTest::testRebuildFromLiveKeepsLiveRowForTrashedLink()
   */
  public function testTrashingMenuLinkInWorkspace(): void {
    // Trash has to be the outermost decorator, ahead of wse_menu's. Without
    // that, its guard never sees these writes and every assertion below would
    // still pass for the wrong reason.
    $this->assertInstanceOf(TrashMenuLinkManager::class, \Drupal::service('plugin.manager.menu.link'));

    $link = $this->createMenuLink('Workspace Link');
    $plugin_id = $link->getPluginId();
    $this->assertSame(1, $this->countMenuTreeRows($plugin_id));

    $workspace = Workspace::create(['label' => 'Trashing']);
    $workspace->save();
    $this->assertArrayHasKey($plugin_id, $this->loadMenuTreeInWorkspace($workspace, 'test-menu'), 'The workspace sees the Live link.');

    $this->workspaceManager->executeInWorkspace($workspace->id(), function () use ($link): void {
      $this->getEntityTypeManager()->getStorage('menu_link_content')->delete([$link]);
    });

    // Live keeps the link. The trash flag is only on the workspace revision, so
    // the published menu must be unaffected.
    $this->assertNotEmpty(MenuLinkContent::load($link->id()));
    $this->assertSame(1, $this->countMenuTreeRows($plugin_id), 'Trashing in a workspace must not touch the Live menu tree.');
    $this->assertArrayHasKey($plugin_id, $this->loadMenuTree('test-menu'), 'The Live menu keeps the link.');

    // The workspace tree drops it, and rendering there must not throw.
    $this->assertArrayNotHasKey($plugin_id, $this->loadMenuTreeInWorkspace($workspace, 'test-menu'), 'Trashing in a workspace removes the link from that workspace tree.');
    $this->assertMenuRendersInWorkspace($workspace);

    // A Live link and a Live rebuild change Live's menu definitions. Neither
    // tree may change for the trashed link.
    $this->createMenuLink('Live Link');
    \Drupal::service('plugin.manager.menu.link')->rebuild();

    $this->assertSame(1, $this->countMenuTreeRows($plugin_id), 'A Live rebuild must not delete the Live row.');
    $this->assertArrayHasKey($plugin_id, $this->loadMenuTree('test-menu'), 'The Live menu keeps the link after a rebuild.');
    $this->assertArrayNotHasKey($plugin_id, $this->loadMenuTreeInWorkspace($workspace, 'test-menu'), 'A Live rebuild must not bring a trashed link back into the workspace tree.');
    $this->assertMenuRendersInWorkspace($workspace);
  }

  /**
   * Tests that publishing a workspace does not leave a trashed link in Live.
   *
   * Publishing makes the workspace revisions default, so the trash applies to
   * Live and the published menu tree has to lose the row. Publishing runs in
   * the 'ignore' trash context, so its writes reach the guard on the menu link
   * manager, and that is what keeps the row out.
   */
  public function testPublishingWorkspaceWithTrashedMenuLink(): void {
    $link = $this->createMenuLink('Published Link');
    $plugin_id = $link->getPluginId();
    $this->assertSame(1, $this->countMenuTreeRows($plugin_id));

    $workspace = Workspace::create(['label' => 'Publishing']);
    $workspace->save();

    $this->workspaceManager->executeInWorkspace($workspace->id(), function () use ($link): void {
      $this->getEntityTypeManager()->getStorage('menu_link_content')->delete([$link]);
    });
    $this->assertSame(1, $this->countMenuTreeRows($plugin_id), 'Live keeps the link while the workspace is unpublished.');

    \Drupal::service('workspaces.operation_factory')->getPublisher($workspace)->publish();

    $this->assertEmpty(MenuLinkContent::load($link->id()), 'The link is trashed in Live after publishing.');
    $this->assertSame(0, $this->countMenuTreeRows($plugin_id), 'Publishing a trashed link must remove its Live menu tree row.');
    $this->assertArrayNotHasKey($plugin_id, $this->loadMenuTree('test-menu'));
  }

  /**
   * Asserts that the test menu renders inside a workspace without throwing.
   */
  protected function assertMenuRendersInWorkspace(WorkspaceInterface $workspace): void {
    $this->workspaceManager->executeInWorkspace($workspace->id(), function (): void {
      try {
        $build = $this->buildMenu('test-menu');
        $this->assertNotEquals(0, $build['#cache']['max-age'], 'The menu render cache should not be poisoned.');
      }
      catch (PluginException $e) {
        $this->fail('Rendering the menu in a workspace threw a PluginException: ' . $e->getMessage());
      }
    });
  }

}
