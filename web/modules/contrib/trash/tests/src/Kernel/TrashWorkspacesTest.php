<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\trash\Trash;
use Drupal\trash\TrashWorkspacesHooks;
use Drupal\workspaces\Entity\Workspace;
use Drupal\workspaces\Hook\WorkspacesHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Trash integration with Workspaces.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashWorkspacesTest extends TrashKernelTestBase {

  use WorkspaceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'path',
    'path_alias',
    'workspaces',
  ];

  /**
   * {@inheritdoc}
   */
  protected array $additionalTrashEntityTypes = ['path_alias' => []];

  /**
   * {@inheritdoc}
   */
  protected function installAdditionalEntitySchemas(): void {
    $this->installEntitySchema('path_alias');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['language']);

    $this->entityTypeManager = \Drupal::entityTypeManager();
    $this->workspaceManager = \Drupal::service('workspaces.manager');

    $this->installSchema('workspaces', ['workspace_association']);
    if (isset(workspaces_schema()['workspace_association_revision'])) {
      $this->installSchema('workspaces', ['workspace_association_revision']);
    }
    $this->installEntitySchema('workspace');

    $this->workspaces['stage'] = Workspace::create(['id' => 'stage', 'label' => 'Stage']);
    $this->workspaces['stage']->save();

    $this->setCurrentUser($this->createUser([
      'view any workspace',
    ]));
  }

  /**
   * Removing a translation in a workspace trashes its alias in the workspace.
   */
  public function testTranslationRemovalInWorkspace(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    // Live site: a node with two translations and an alias for each.
    $node = $this->createNode(['type' => 'article', 'title' => 'EN']);
    $node->addTranslation('de', ['title' => 'DE']);
    $node->save();
    $node_id = $node->id();

    $de_alias = PathAlias::create([
      'path' => '/node/' . $node_id,
      'alias' => '/de-alias',
      'langcode' => 'de',
    ]);
    $de_alias->save();
    $alias_id = $de_alias->id();

    // Remove the DE translation inside the workspace. The trash events fire
    // for the workspace save, so the alias is trashed as a workspace-scoped
    // change.
    $this->switchToWorkspace('stage');
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    $node->removeTranslation('de');
    $node->save();

    $this->assertEmpty(PathAlias::load($alias_id));
    $workspace_node = Node::load($node_id);
    $this->assertTrue(Trash::entityIsDeleted($workspace_node->getTranslation('de')));

    // The live site is untouched: the translation and its alias stay live
    // until the workspace is published.
    $this->workspaceManager->executeOutsideWorkspace(function () use ($node_id, $alias_id): void {
      $this->assertNotEmpty(PathAlias::load($alias_id));
      $live_node = Node::load($node_id);
      $this->assertTrue($live_node->hasTranslation('de'));
      $this->assertFalse(Trash::entityIsDeleted($live_node->getTranslation('de')));
    });
  }

  /**
   * Test trashing entities in a workspace.
   */
  public function testDeletion(): void {
    // The class-named hook service must resolve to the trash decorator, and
    // the decorator must still satisfy a WorkspacesHooks type-hint: other
    // modules inject the concrete class, so a decorator that is not a
    // WorkspacesHooks itself makes them fatal with a TypeError.
    $hooks = $this->container->get(WorkspacesHooks::class);
    $this->assertInstanceOf(TrashWorkspacesHooks::class, $hooks);
    $this->assertInstanceOf(WorkspacesHooks::class, $hooks);

    // The decorator never initializes the parent's constructor-promoted
    // properties, which is only safe while it overrides every public method
    // WorkspacesHooks declares itself with an explicit delegation to the
    // decorated service. Fail loudly when core adds a new hook method.
    // Methods composed from traits are skipped: they only touch the trait's
    // own properties, never the uninitialized constructor dependencies.
    $parent_reflection = new \ReflectionClass(WorkspacesHooks::class);
    foreach ($parent_reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      if ($method->isConstructor() || $method->isStatic() || $method->getFileName() !== $parent_reflection->getFileName()) {
        continue;
      }
      $declaring_class = (new \ReflectionMethod(TrashWorkspacesHooks::class, $method->getName()))->getDeclaringClass()->getName();
      $this->assertSame(TrashWorkspacesHooks::class, $declaring_class, sprintf('TrashWorkspacesHooks must override WorkspacesHooks::%s().', $method->getName()));
    }

    $live_node = $this->createNode(['type' => 'article']);
    $live_node->save();

    $edited_node = $this->createNode(['type' => 'article']);
    $edited_node->save();

    // Activate a workspace and delete the node.
    $this->switchToWorkspace('stage');

    $ws_node = $this->createNode(['type' => 'article']);
    $ws_node->save();

    $live_node->delete();
    $ws_node->delete();

    $edited_node->setTitle('Edited in stage');
    $edited_node->save();

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');

    // Check that the nodes are marked as deleted, ignoring the trash context.
    $forced_live_node = $this->forceLoadEntity($storage, $live_node->id());
    $forced_ws_node = $this->forceLoadEntity($storage, $ws_node->id());

    $this->assertTrue(Trash::entityIsDeleted($forced_live_node));
    $this->assertTrue(Trash::entityIsDeleted($forced_ws_node));

    // Check loading the deleted nodes in a workspace.
    $this->assertEmpty($storage->load($forced_live_node->id()));
    $this->assertEmpty($storage->loadRevision($forced_live_node->getRevisionId()));

    $this->assertEmpty($storage->load($forced_ws_node->id()));
    $this->assertEmpty($storage->loadRevision($forced_ws_node->getRevisionId()));

    // Switch back to Live and check that the nodes are not marked as deleted.
    $this->switchToLive();

    /** @var \Drupal\node\NodeInterface $live_node */
    $live_node = $storage->load($live_node->id());
    $this->assertNotEmpty($live_node);
    $this->assertTrue($live_node->isPublished());
    $this->assertNotEmpty($storage->loadRevision($live_node->getRevisionId()));
    $this->assertFalse(Trash::entityIsDeleted($live_node));

    $ws_node = $storage->load($ws_node->id());
    $this->assertNotEmpty($ws_node);
    $this->assertFalse($ws_node->isPublished());
    $this->assertNotEmpty($storage->loadRevision($ws_node->getRevisionId()));
    $this->assertFalse(Trash::entityIsDeleted($ws_node));

    // Loading in Live puts the default revision back in the persistent entity
    // cache, which is keyed by entity ID only and not varied by workspace.
    $this->assertNotEmpty(\Drupal::cache('entity')->get('values:node:' . $live_node->id()));

    // Switching workspaces clears entity.memory_cache for every supported
    // entity type, so these loads go through the persistent cache. The node
    // trashed in this workspace must not be served from it, while a tracked
    // revision that is not trashed must still resolve.
    $this->switchToWorkspace('stage');
    $this->assertEmpty($storage->load($live_node->id()));
    $this->assertEmpty($storage->loadMultiple([$live_node->id()]));
    $this->assertSame('Edited in stage', $storage->load($edited_node->id())->getTitle());

    $this->switchToLive();

    // Publish the workspace and check that both nodes are now deleted in Live.
    $this->workspaces['stage']->publish();

    $this->assertEmpty($storage->load($live_node->id()));
    $this->assertEmpty($storage->load($ws_node->id()));
  }

  /**
   * Tests 'purge' access for entities and translations across workspaces.
   */
  public function testPurgeAccess(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    $this->setCurrentUser($this->createUser([
      'access content',
      'view any workspace',
      'purge node entities',
    ]));

    $live_node = $this->createNode(['type' => 'article']);
    $live_node->addTranslation('de', ['title' => 'DE title']);
    $live_node->save();

    // A second Live node with only its German translation trashed; English
    // stays live, so the entity is only partially trashed.
    $partial_node = $this->createNode(['type' => 'article']);
    $partial_node->addTranslation('de', ['title' => 'DE title']);
    $partial_node->save();
    $partial_node = Node::load($partial_node->id());
    $partial_node->removeTranslation('de');
    $partial_node->save();

    // Activate a workspace and create a second node there.
    $this->switchToWorkspace('stage');

    $ws_node = $this->createNode(['type' => 'article']);
    $ws_node->addTranslation('de', ['title' => 'DE title']);
    $ws_node->save();

    $live_node->delete();
    $ws_node->delete();

    $storage = $this->entityTypeManager->getStorage('node');

    $live_node = $this->forceLoadEntity($storage, $live_node->id());
    $ws_node = $this->forceLoadEntity($storage, $ws_node->id());

    // Entities can only be purged in the workspace they were created in.
    $this->assertFalse($live_node->access('purge'));
    $this->assertTrue($ws_node->access('purge'));

    // The workspace guard runs before the per-translation permission split, so
    // it guards a translation purge the same way: the deleted German
    // translation follows the same workspace boundary as the whole entity.
    $live_de = $this->forceLoadEntity($storage, $live_node->id(), 'de');
    $ws_de = $this->forceLoadEntity($storage, $ws_node->id(), 'de');
    $this->assertFalse($live_de->access('purge'));
    $this->assertTrue($ws_de->access('purge'));

    // Purging the trashed German translation of a partially trashed Live node
    // from a non-owning workspace must be forbidden. The delete-is-harmless
    // override (English still live) must not leak into the purge access check.
    $partial_de = $this->forceLoadEntity($storage, $partial_node->id(), 'de');
    $this->assertTrue(Trash::entityIsDeleted($partial_de));
    $this->assertFalse($partial_de->access('purge'));
  }

}
