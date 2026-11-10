<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\system\Entity\Menu;
use Drupal\trash\Handler\DefaultTrashHandler;
use Drupal\trash\Hook\TrashHandler\MenuLinkContentTrashHandler;
use Drupal\trash\Menu\TrashMenuLinkManager;
use Drupal\trash\TrashStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests menu_link_content integration with the Trash module.
 */
#[CoversClass(MenuLinkContentTrashHandler::class)]
#[CoversClass(DefaultTrashHandler::class)]
#[CoversClass(TrashMenuLinkManager::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashMenuLinkTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'link',
    'menu_link_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected array $additionalTrashEntityTypes = ['menu_link_content' => []];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Menu::create([
      'id' => 'test-menu',
      'label' => 'Test menu',
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function installAdditionalEntitySchemas(): void {
    $this->installEntitySchema('menu_link_content');
  }

  /**
   * Creates a menu link in the test menu.
   */
  protected function createMenuLink(string $title, string $uri = 'internal:/', ?string $parent_uuid = NULL, string $menu_name = 'test-menu'): MenuLinkContent {
    $values = [
      'title' => $title,
      'link' => [['uri' => $uri]],
      'menu_name' => $menu_name,
    ];
    if ($parent_uuid !== NULL) {
      $values['parent'] = 'menu_link_content:' . $parent_uuid;
    }
    $link = MenuLinkContent::create($values);
    $link->save();
    return $link;
  }

  /**
   * Loads the menu tree for the test menu.
   */
  protected function loadMenuTree(string $menu_name): array {
    return \Drupal::menuTree()->load($menu_name, new MenuTreeParameters());
  }

  /**
   * Builds the menu render array with access checking, like SystemMenuBlock.
   *
   * @return array
   *   The menu render array with #cache metadata.
   */
  protected function buildMenu(string $menu_name): array {
    $menu_tree = \Drupal::menuTree();
    $tree = $this->loadMenuTree($menu_name);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $menu_tree->transform($tree, $manipulators);
    return $menu_tree->build($tree);
  }

  /**
   * Counts the {menu_tree} rows for a menu link plugin ID.
   */
  protected function countMenuTreeRows(string $plugin_id): int {
    return (int) $this->container->get('database')->select('menu_tree', 't')
      ->condition('t.id', $plugin_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Asserts that a trashed link left nothing behind in the menu tree.
   *
   * Checks the {menu_tree} row, the loaded tree, and an access-checked render.
   * A leftover row shows up in the render as a PluginException, because
   * MenuLinkContent::getEntity() cannot resolve a trashed entity by ID or by
   * its UUID fallback.
   */
  protected function assertNoStaleMenuTreeEntry(string $plugin_id, string $menu_name = 'test-menu'): void {
    $this->assertSame(0, $this->countMenuTreeRows($plugin_id), 'A trashed menu link should have no {menu_tree} row.');
    $this->assertArrayNotHasKey($plugin_id, $this->loadMenuTree($menu_name));

    try {
      $build = $this->buildMenu($menu_name);
      $this->assertNotEquals(0, $build['#cache']['max-age'], 'The menu render cache should not be poisoned.');
    }
    catch (PluginException $e) {
      $this->fail('Rendering the menu threw a PluginException for a trashed menu link: ' . $e->getMessage());
    }
  }

  /**
   * Data provider for trash contexts in which trashed entities are visible.
   *
   * The 'ignore' context drops the 'deleted' filter, so storage loads and
   * entity queries return live and trashed entities alike. The 'inactive'
   * context inverts it and returns only the trashed ones.
   *
   * @see \Drupal\trash\Hook\TrashEntityHooks::entityQueryAlter()
   */
  public static function providerNonActiveTrashContexts(): array {
    return [
      'ignore' => ['ignore'],
      'inactive' => ['inactive'],
    ];
  }

  /**
   * Tests translation-aware menu link cascade against a target's translations.
   *
   * Removing one target translation keeps the link; deleting the whole target
   * trashes it and restoring the target brings it back. A link translation
   * trashed on its own (a different timestamp) is left in the trash when the
   * target is restored.
   */
  public function testMenuLinkTranslationCascade(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    // Removing one target translation keeps it partially live, so the link
    // must not be trashed. Deleting the whole target then trashes it, and
    // restoring the target restores it.
    $node = $this->createNode(['type' => 'article', 'title' => 'EN']);
    $node->addTranslation('de', ['title' => 'DE']);
    $node->save();
    $node_id = $node->id();
    $link = $this->createMenuLink('Node link', 'entity:node/' . $node_id);
    $link_id = $link->id();
    $node = Node::load($node_id);
    $node->removeTranslation('de');
    $node->save();
    $this->assertNotEmpty(MenuLinkContent::load($link_id));
    Node::load($node_id)->delete();
    $this->assertEmpty(MenuLinkContent::load($link_id));
    $this->restoreEntity('node', $node_id);
    $this->assertNotEmpty(MenuLinkContent::load($link_id));

    // A link translation removed on its own before the target's cascade has a
    // different timestamp, so restoring the target leaves it in the trash.
    $node2 = $this->createNode(['type' => 'article', 'title' => 'EN']);
    $node2_id = $node2->id();
    $link2 = $this->createMenuLink('Node2 link EN', 'entity:node/' . $node2_id);
    $link2_id = $link2->id();
    $link2->addTranslation('de', ['title' => 'Node2 link DE']);
    $link2->save();
    $link2 = MenuLinkContent::load($link2_id);
    $link2->removeTranslation('de');
    $link2->save();
    $trashed_link = $this->loadTrashedEntity('menu_link_content', $link2_id);
    $this->assertNotEmpty($trashed_link->getTranslation('de')->get('deleted')->value);
    $this->assertEmpty($trashed_link->getTranslation('en')->get('deleted')->value);
    // Later, trash the node, cascading only the link's still-live EN.
    \Drupal::keyValue('trash_test')->set('time_offset', 100);
    Node::load($node2_id)->delete();
    $this->assertNotEmpty($this->loadTrashedEntity('menu_link_content', $link2_id)->getTranslation('en')->get('deleted')->value);
    // Restoring the node restores the cascaded EN but leaves the independently
    // trashed DE in the trash.
    $this->restoreEntity('node', $node2_id);
    $link2 = MenuLinkContent::load($link2_id);
    $this->assertNotEmpty($link2);
    $this->assertEmpty($link2->get('deleted')->value);
    $this->assertNotEmpty($this->loadTrashedEntity('menu_link_content', $link2_id)->getTranslation('de')->get('deleted')->value);
  }

  /**
   * Tests that a menu link can be soft-deleted and restored.
   */
  public function testMenuLinkDeleteAndRestore(): void {
    $link = $this->createMenuLink('Test Link', 'internal:/user/login');
    $link->set('weight', 5);
    $link->set('expanded', TRUE);
    $link->set('enabled', TRUE);
    $link->save();
    $link_id = $link->id();
    $plugin_id = $link->getPluginId();

    // Verify the link exists and is in the menu tree.
    $this->assertNotEmpty(MenuLinkContent::load($link_id));
    $this->assertArrayHasKey($plugin_id, $this->loadMenuTree('test-menu'));

    // Trash the link.
    $link->delete();

    // Verify the link is not loadable and is removed from the menu tree.
    $this->assertEmpty(MenuLinkContent::load($link_id));
    $this->assertArrayNotHasKey($plugin_id, $this->loadMenuTree('test-menu'));

    // Verify the link is accessible in trash with a deleted timestamp.
    $deleted_link = $this->loadTrashedEntity('menu_link_content', $link_id);
    $this->assertNotEmpty($deleted_link);
    $this->assertNotEmpty($deleted_link->get('deleted')->value);

    // Restore the link and verify it is back in the menu tree with all its
    // properties preserved.
    $this->restoreEntity('menu_link_content', $link_id);
    $restored_link = MenuLinkContent::load($link_id);
    $this->assertNotEmpty($restored_link);
    $this->assertEmpty($restored_link->get('deleted')->value);
    $this->assertEquals('Test Link', $restored_link->getTitle());
    $this->assertEquals('internal:/user/login', $restored_link->get('link')->getValue()[0]['uri']);
    $this->assertEquals('test-menu', $restored_link->getMenuName());
    $this->assertEquals(5, $restored_link->getWeight());
    $this->assertTrue($restored_link->isExpanded());
    $this->assertTrue($restored_link->isEnabled());
    $this->assertArrayHasKey($plugin_id, $this->loadMenuTree('test-menu'));
  }

  /**
   * Tests that trashing a node cascades to its menu links.
   */
  public function testNodeTrashCascadesToMenuLinks(): void {
    $node_storage = $this->getEntityTypeManager()->getStorage('node');
    assert($node_storage instanceof TrashStorageInterface);

    // Trashing and restoring a node without menu links should not cause errors.
    $node_without_links = $this->createNode(['type' => 'article']);
    $node_without_links->delete();
    $node_storage->restoreFromTrash([$node_without_links]);
    $this->assertNotEmpty(Node::load($node_without_links->id()));

    // Create a second menu so we can test links in different menus.
    Menu::create(['id' => 'other-menu', 'label' => 'Other menu'])->save();

    // Create two nodes: node1 with two menu links, node2 with one.
    $node1 = $this->createNode(['type' => 'article', 'title' => 'Node 1']);
    $link1a = $this->createMenuLink('Link 1a', 'entity:node/' . $node1->id());
    $link1a->set('weight', 5);
    $link1a->set('expanded', TRUE);
    $link1a->save();
    $link1b = $this->createMenuLink('Link 1b', 'entity:node/' . $node1->id(), NULL, 'other-menu');

    $node2 = $this->createNode(['type' => 'article', 'title' => 'Node 2']);
    $link2 = $this->createMenuLink('Link 2', 'entity:node/' . $node2->id());

    // Trash both nodes in one call so they share the same deletion timestamp.
    $node_storage->delete([$node1, $node2]);

    // All three menu links should be cascade-trashed.
    $this->assertEmpty(MenuLinkContent::load($link1a->id()));
    $this->assertEmpty(MenuLinkContent::load($link1b->id()));
    $this->assertEmpty(MenuLinkContent::load($link2->id()));

    // Verify timestamps match the node's.
    $deleted_node1 = $this->loadTrashedEntity('node', $node1->id());
    $deleted_link1a = $this->loadTrashedEntity('menu_link_content', $link1a->id());
    $deleted_link1b = $this->loadTrashedEntity('menu_link_content', $link1b->id());
    $this->assertEquals($deleted_node1->get('deleted')->value, $deleted_link1a->get('deleted')->value);
    $this->assertEquals($deleted_node1->get('deleted')->value, $deleted_link1b->get('deleted')->value);

    // Verify links are gone from the menu tree.
    $this->assertArrayNotHasKey($link1a->getPluginId(), $this->loadMenuTree('test-menu'));
    $this->assertArrayNotHasKey($link1b->getPluginId(), $this->loadMenuTree('other-menu'));

    // The menu block render cache should not be poisoned with max-age=0.
    $this->assertNotEquals(0, $this->buildMenu('test-menu')['#cache']['max-age']);
    $this->assertNotEquals(0, $this->buildMenu('other-menu')['#cache']['max-age']);

    // Restore only node1.
    $node_storage->restoreFromTrash([$node1]);

    // Node1's links should be restored with properties intact.
    $restored_link1a = MenuLinkContent::load($link1a->id());
    $restored_link1b = MenuLinkContent::load($link1b->id());
    $this->assertNotEmpty($restored_link1a);
    $this->assertNotEmpty($restored_link1b);
    $this->assertNull($restored_link1a->get('deleted')->value);
    $this->assertNull($restored_link1b->get('deleted')->value);
    $this->assertEquals('Link 1a', $restored_link1a->getTitle());
    $this->assertEquals(5, $restored_link1a->getWeight());
    $this->assertTrue($restored_link1a->isExpanded());
    $this->assertEquals('test-menu', $restored_link1a->getMenuName());
    $this->assertEquals('other-menu', $restored_link1b->getMenuName());

    // Both links should be back in the menu tree.
    $this->assertArrayHasKey($restored_link1a->getPluginId(), $this->loadMenuTree('test-menu'));
    $this->assertArrayHasKey($restored_link1b->getPluginId(), $this->loadMenuTree('other-menu'));

    // Node2's link should still be trashed.
    $this->assertEmpty(MenuLinkContent::load($link2->id()));
    $this->assertNotEmpty($this->loadTrashedEntity('menu_link_content', $link2->id()));

    // Purging node2 should also purge its cascade-trashed menu link.
    $this->purgeEntity('node', $node2->id());
    $this->assertEmpty($this->loadTrashedEntity('node', $node2->id()));
    $this->assertEmpty($this->loadTrashedEntity('menu_link_content', $link2->id()));
  }

  /**
   * Tests that cascade is skipped when menu_link_content is not trashable.
   */
  public function testMenuLinkContentDisabledSkipsIntegration(): void {
    $this->disableEntityTypesForTrash(['menu_link_content']);

    $node = $this->createNode(['type' => 'article']);
    $link = $this->createMenuLink('Link', 'entity:node/' . $node->id());

    $node->delete();

    // The node is trashed but the menu link remains active.
    $this->assertEmpty(Node::load($node->id()));
    $this->assertNotEmpty(MenuLinkContent::load($link->id()));

    // Without the integration, the orphaned menu link poisons the render cache
    // with max-age=0 because the access check cannot load the trashed node.
    $this->assertEquals(0, $this->buildMenu('test-menu')['#cache']['max-age']);
  }

  /**
   * Tests hierarchy handling when menu links are trashed and restored.
   *
   * Covers moving children to a new parent on trash, verifying that restoring
   * a trashed link does not undo the move, and that trashing a top-level link
   * moves its children to the root.
   */
  public function testMenuLinkHierarchy(): void {
    // Create hierarchy:
    // - parent
    // -- child-1
    // --- grandchild-1
    // --- grandchild-2
    // -- child-2.
    $parent = $this->createMenuLink('Parent');
    $child1 = $this->createMenuLink('Child 1', 'internal:/', $parent->uuid());
    $grandchild1 = $this->createMenuLink('Grandchild 1', 'internal:/', $child1->uuid());
    $grandchild2 = $this->createMenuLink('Grandchild 2', 'internal:/', $child1->uuid());
    $child2 = $this->createMenuLink('Child 2', 'internal:/', $parent->uuid());

    // Trash child-1: both grandchildren should be moved to parent, and child-2
    // should be unaffected.
    $child1->delete();

    $grandchild1 = MenuLinkContent::load($grandchild1->id());
    $grandchild2 = MenuLinkContent::load($grandchild2->id());
    $child2 = MenuLinkContent::load($child2->id());
    $this->assertEquals('menu_link_content:' . $parent->uuid(), $grandchild1->getParentId());
    $this->assertEquals('menu_link_content:' . $parent->uuid(), $grandchild2->getParentId());
    $this->assertEquals('menu_link_content:' . $parent->uuid(), $child2->getParentId());

    // The menu tree should reflect the updated hierarchy.
    $tree = $this->loadMenuTree('test-menu');
    $this->assertCount(3, $tree[$parent->getPluginId()]->subtree);

    // Purge (permanently delete) child-1.
    $this->purgeEntity('menu_link_content', $child1->id());

    // Verify child-1 is permanently gone.
    $this->assertEmpty($this->loadTrashedEntity('menu_link_content', $child1->id()));

    // Verify moved children are still intact after the purge.
    $grandchild1 = MenuLinkContent::load($grandchild1->id());
    $grandchild2 = MenuLinkContent::load($grandchild2->id());
    $this->assertEquals('menu_link_content:' . $parent->uuid(), $grandchild1->getParentId());
    $this->assertEquals('menu_link_content:' . $parent->uuid(), $grandchild2->getParentId());
    $tree = $this->loadMenuTree('test-menu');
    $this->assertCount(3, $tree[$parent->getPluginId()]->subtree);

    // Now test the restore flow with a fresh trashed link. Trash child-2: it
    // has no children, so no hierarchy changes occur.
    $child2->delete();

    // Restore child-2: it should come back under parent.
    $this->restoreEntity('menu_link_content', $child2->id());

    $restored_child2 = MenuLinkContent::load($child2->id());
    $this->assertNotEmpty($restored_child2);
    $this->assertEquals('menu_link_content:' . $parent->uuid(), $restored_child2->getParentId());

    // Parent now has 3 direct children (child-2, grandchild-1, grandchild-2).
    $tree = $this->loadMenuTree('test-menu');
    $this->assertCount(3, $tree[$parent->getPluginId()]->subtree);

    // Trash the top-level parent: all its children become root-level items.
    $parent->delete();

    $restored_child2 = MenuLinkContent::load($restored_child2->id());
    $this->assertEquals('', $restored_child2->getParentId());
    $grandchild1 = MenuLinkContent::load($grandchild1->id());
    $this->assertEquals('', $grandchild1->getParentId());

    $tree = $this->loadMenuTree('test-menu');
    $this->assertArrayNotHasKey($parent->getPluginId(), $tree);
    $this->assertArrayHasKey($restored_child2->getPluginId(), $tree);
    $this->assertArrayHasKey($grandchild1->getPluginId(), $tree);

    // Restore the parent: it comes back as a root-level item. Children that
    // were already moved to root stay there.
    $this->restoreEntity('menu_link_content', $parent->id());
    $restored_parent = MenuLinkContent::load($parent->id());
    $this->assertNotEmpty($restored_parent);
    $this->assertEquals('', $restored_parent->getParentId());

    $tree = $this->loadMenuTree('test-menu');
    $this->assertArrayHasKey($restored_parent->getPluginId(), $tree);
    $this->assertEmpty($tree[$restored_parent->getPluginId()]->subtree);
    $this->assertArrayHasKey($restored_child2->getPluginId(), $tree);
    $this->assertArrayHasKey($grandchild1->getPluginId(), $tree);
  }

  /**
   * Tests that resaving a trashed menu link does not put it back in the tree.
   *
   * MenuLinkContent::postSave() calls addDefinition(), so any save of a
   * trash-flagged link writes a {menu_tree} row again. The entity stays
   * filtered out of storage, so MenuLinkContent::getEntity() then throws a
   * PluginException on every menu walk that checks access, including anonymous
   * page renders.
   *
   * Callers that hit this in practice: workspace publishing, which runs with
   * the 'ignore' context for the whole operation; any request on a trash admin
   * route, including bulk operation submits; and code that saves links it found
   * through an entity query, such as a path alias update rewriting link URIs.
   * The second half covers that query shape, where the link is reached by
   * 'link.uri' instead of by ID.
   *
   * A menu rebuild does not clean this up. MenuTreeStorage's
   * findNoLongerExistingLinks() only considers rows with discovered = 1, and
   * entity-backed rows have discovered = 0, so the row survives until the link
   * is purged or restored.
   */
  #[DataProvider('providerNonActiveTrashContexts')]
  public function testResavingTrashedLinkDoesNotReAddMenuTreeEntry(string $context): void {
    $link = $this->createMenuLink('Test Link');
    $link_id = $link->id();
    $plugin_id = $link->getPluginId();

    $link->delete();
    $this->assertSame(0, $this->countMenuTreeRows($plugin_id), 'Trashing the link should remove its {menu_tree} row.');

    // Resave the trashed link outside the 'active' trash context.
    $this->getTrashManager()->executeInTrashContext($context, function () use ($link_id, $context): void {
      $trashed = $this->getEntityTypeManager()->getStorage('menu_link_content')->loadUnchanged($link_id);
      $this->assertNotEmpty($trashed, "A trashed link should be loadable in the '$context' trash context.");
      $trashed->set('title', 'Test Link updated');
      $trashed->save();
    });

    // The link is still trashed, so it has to stay out of the menu tree.
    $this->assertEmpty(MenuLinkContent::load($link_id));
    $this->assertNoStaleMenuTreeEntry($plugin_id);

    // Same again for a link reached through an entity query.
    $aliased = $this->createMenuLink('Aliased Link', 'internal:/old-alias');
    $aliased_id = $aliased->id();
    $aliased_plugin_id = $aliased->getPluginId();

    $aliased->delete();
    $this->assertSame(0, $this->countMenuTreeRows($aliased_plugin_id));

    $this->getTrashManager()->executeInTrashContext($context, function () use ($context): void {
      $storage = $this->getEntityTypeManager()->getStorage('menu_link_content');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('link.uri', 'internal:/old-alias')
        ->execute();
      $this->assertNotEmpty($ids, "The entity query should return the trashed link in the '$context' trash context.");
      $storage->resetCache(array_values($ids));
      foreach ($storage->loadMultiple(array_values($ids)) as $found) {
        $found->set('link', [['uri' => 'internal:/new-alias']]);
        $found->save();
      }
    });

    $this->assertEmpty(MenuLinkContent::load($aliased_id));
    $this->assertNoStaleMenuTreeEntry($aliased_plugin_id);
  }

  /**
   * Tests that removing a parent does not resurrect an already-trashed child.
   *
   * MenuLinkContent::preDelete() re-parents children with $child->save(). With
   * a non-active trash context, loadByProperties() also returns trashed
   * children, so they get resaved and land back in {menu_tree} while still
   * being trash-flagged. Purging a parent takes the same path, and runs with
   * the trash context set to 'ignore' from both the purge form and the cron
   * queue worker.
   */
  #[DataProvider('providerNonActiveTrashContexts')]
  public function testRemovingParentDoesNotResurrectTrashedChild(string $context): void {
    $parent = $this->createMenuLink('Parent');
    $child = $this->createMenuLink('Child', 'internal:/', $parent->uuid());
    $parent_id = $parent->id();
    $child_id = $child->id();
    $child_plugin_id = $child->getPluginId();

    // Trash the child first, then remove the parent in that context.
    $child->delete();
    $this->assertSame(0, $this->countMenuTreeRows($child_plugin_id));

    $this->getTrashManager()->executeInTrashContext($context, function () use ($parent_id): void {
      $parent = $this->getEntityTypeManager()->getStorage('menu_link_content')->loadUnchanged($parent_id);
      $this->assertNotEmpty($parent);
      $parent->delete();
    });

    $this->assertNotEmpty($this->loadTrashedEntity('menu_link_content', $child_id), 'The child should still be trashed.');
    $this->assertNoStaleMenuTreeEntry($child_plugin_id);

    // Purging a parent reaches the same re-parenting path.
    $purged_parent = $this->createMenuLink('Purged parent');
    $purged_child = $this->createMenuLink('Purged child', 'internal:/', $purged_parent->uuid());
    $purged_child_id = $purged_child->id();
    $purged_child_plugin_id = $purged_child->getPluginId();

    $purged_child->delete();
    $this->assertSame(0, $this->countMenuTreeRows($purged_child_plugin_id));

    $this->purgeEntity('menu_link_content', $purged_parent->id());

    $this->assertNotEmpty($this->loadTrashedEntity('menu_link_content', $purged_child_id), 'The child should still be trashed.');
    $this->assertNoStaleMenuTreeEntry($purged_child_plugin_id);
  }

  /**
   * Tests that a menu rebuild does not put a trashed link back in the tree.
   *
   * MenuLinkContentDeriver returns trashed links in those contexts, so a
   * rebuild would otherwise write a row for one. A rebuild collects its
   * definitions inside the inner manager, past the decorator's guard, so
   * TrashMenuLinkManager::rebuild() runs the whole rebuild in the 'active'
   * trash context and the deriver's own entity query filters the trashed
   * links out.
   *
   * The live link covers the 'inactive' case: that context inverts the entity
   * query filter, so without the forced context a rebuild would drop every
   * live link from discovery and purge their rows.
   */
  #[DataProvider('providerNonActiveTrashContexts')]
  public function testMenuRebuildDoesNotReAddTrashedLink(string $context): void {
    $live = $this->createMenuLink('Live Link');
    $live_plugin_id = $live->getPluginId();
    $link = $this->createMenuLink('Test Link');
    $plugin_id = $link->getPluginId();

    // A rebuild marks both rows 'discovered', the state every rebuilt site is
    // in. Only marked rows are purged when a link falls out of discovery, so
    // without this the live link below would survive a bad rebuild by
    // accident.
    \Drupal::service('plugin.manager.menu.link')->rebuild();

    $database = $this->container->get('database');
    $row = $database->select('menu_tree', 't')
      ->fields('t')
      ->condition('t.id', $plugin_id)
      ->execute()
      ->fetchAssoc();

    $link->delete();
    $this->assertSame(0, $this->countMenuTreeRows($plugin_id));

    // Recreate the row an unguarded rebuild used to leave behind. The rebuild
    // below has to purge it.
    $database->insert('menu_tree')->fields($row)->execute();
    $this->assertSame(1, $this->countMenuTreeRows($plugin_id));

    $this->getTrashManager()->executeInTrashContext($context, function () use ($context): void {
      $this->container->get('plugin.manager.menu.link')->rebuild();
      $this->assertSame($context, $this->getTrashManager()->getTrashContext(), 'rebuild() must restore the ambient trash context.');
    });

    $this->assertEmpty(MenuLinkContent::load($link->id()));
    $this->assertNoStaleMenuTreeEntry($plugin_id);

    $this->assertSame(1, $this->countMenuTreeRows($live_plugin_id), 'A rebuild must not purge the row of a live link.');
    $this->assertArrayHasKey($live_plugin_id, $this->loadMenuTree('test-menu'));
  }

  /**
   * Tests that a partially trashed link keeps its row across a menu rebuild.
   *
   * A menu_tree row covers every language of a link, so a link with any live
   * translation left has to stay in the tree and in discovery. The deriver's
   * entity query in the forced 'active' context matches exactly those links.
   */
  public function testPartiallyTrashedLinkSurvivesMenuRebuild(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    $link = $this->createMenuLink('Partial Link');
    $link->addTranslation('de', ['title' => 'Partial Link DE']);
    $link->save();
    $link_id = $link->id();
    $plugin_id = $link->getPluginId();
    $this->assertSame(1, $this->countMenuTreeRows($plugin_id));

    // Trash only the 'de' translation.
    $link = MenuLinkContent::load($link_id);
    $link->removeTranslation('de');
    $link->save();
    $this->assertSame(1, $this->countMenuTreeRows($plugin_id), 'A link with a live translation left keeps its menu tree row.');

    $manager = \Drupal::service('plugin.manager.menu.link');
    $manager->rebuild();

    $this->assertSame(1, $this->countMenuTreeRows($plugin_id));
    $this->assertArrayHasKey($plugin_id, $manager->getDefinitions(), 'A link with a live translation left stays in discovery.');
    $this->assertArrayHasKey($plugin_id, $this->loadMenuTree('test-menu'));
    $this->assertNotEquals(0, $this->buildMenu('test-menu')['#cache']['max-age']);
  }

}
