<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\node\NodeBulkUpdate;
use Drupal\trash\Cache\TrashEntityCacheBackend;
use Drupal\trash\EventSubscriber\TrashIgnoreSubscriber;
use Drupal\trash\Handler\TrashHandlerPass;
use Drupal\trash\LayoutBuilder\TrashInlineBlockUsage;
use Drupal\trash\Menu\TrashMenuLinkManager;
use Drupal\workspaces\Hook\WorkspacesHooks;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Alters container services.
 */
class TrashServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    $container->addCompilerPass(new TrashHandlerPass());

    // The generated storage classes are named after this hash, so that a
    // changed template produces a new class name and every web head picks up
    // the new code. Hashing here rather than per request keeps it out of the
    // runtime path: the value is compiled into the container.
    // @see \Drupal\trash\TrashEntityTypeManager::generateStorageClass()
    $container->setParameter('trash.storage_class_hash', substr(hash_file('xxh128', __DIR__ . '/../templates/TrashStorage.php.twig'), 0, 8));
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('workspaces.information')) {
      $container->register('trash.workspaces.information', TrashWorkspaceInformation::class)
        ->setPublic(FALSE)
        ->setDecoratedService('workspaces.information', NULL, 100)
        ->addArgument(new Reference('trash.workspaces.information.inner'))
        ->addArgument(new Reference('trash.manager'));

      $container->getDefinition(TrashEntityCacheBackend::class)
        ->setArgument('$workspaceManager', new Reference('workspaces.manager'))
        ->setArgument('$workspaceInformation', new Reference('workspaces.information'))
        ->setArgument('$workspaceTracker', new Reference('workspaces.tracker'));
    }

    if ($container->hasDefinition(WorkspacesHooks::class)) {
      $container->register('trash.workspaces.hooks', TrashWorkspacesHooks::class)
        ->setPublic(FALSE)
        ->setDecoratedService(WorkspacesHooks::class)
        ->addArgument(new Reference('trash.workspaces.hooks.inner'))
        ->addArgument(new Reference('trash.manager'));
    }

    if ($container->hasDefinition('inline_block.usage')) {
      $container->register('trash.inline_block.usage', TrashInlineBlockUsage::class)
        ->setPublic(FALSE)
        ->setDecoratedService('inline_block.usage')
        ->addArgument(new Reference('trash.inline_block.usage.inner'))
        ->addArgument(new Reference('trash.manager'));
    }

    if ($container->hasDefinition(NodeBulkUpdate::class)) {
      $container->register('trash.node.bulk_update', TrashNodeBulkUpdate::class)
        ->setPublic(FALSE)
        ->setDecoratedService(NodeBulkUpdate::class)
        ->setAutowired(TRUE)
        ->addArgument(new Reference('trash.node.bulk_update.inner'));
    }

    if ($container->hasDefinition('wse_menu.tree_storage')) {
      $container->getDefinition('wse_menu.tree_storage')
        ->setClass(TrashWseMenuTreeStorage::class)
        ->addMethodCall('setTrashManager', [new Reference('trash.manager')]);
    }

    // Guard at the menu link manager rather than at menu.tree_storage: core's
    // workspaces and wse_menu both decorate that service and drop the inner
    // one, and contrib or custom code may do the same, so a class set there can
    // stop applying.
    //
    // The lowest decoration priority keeps this guard outermost, so no other
    // decorator can add a trashed link back before the guard sees the call.
    // wse_menu, for one, decorates the same service at priority 50.
    if ($container->hasDefinition('plugin.manager.menu.link')) {
      $container->register('trash.plugin.manager.menu.link', TrashMenuLinkManager::class)
        ->setPublic(FALSE)
        ->setDecoratedService('plugin.manager.menu.link', NULL, -100)
        ->addArgument(new Reference('trash.plugin.manager.menu.link.inner'))
        ->addArgument(new Reference('entity_type.manager'))
        ->addArgument(new Reference('entity.repository'));
    }

    // Ensure the trash ignore subscriber is one of the first definitions used
    // after authentication. This is necessary because in the event that two
    // subscriber event listeners have the same priority, then the one which was
    // registered first takes precedence. We must ensure that the ignore
    // subscriber is one of the first subscribers with a priority of 299.
    $trash_ignore_subscriber = TrashIgnoreSubscriber::class;

    // This is one of the earliest 'kernel.request' event listeners with a
    // priority of 299, so we must ensure the ignore subscriber is added
    // before it.
    $target = 'system.timezone_resolver';

    if ($container->hasDefinition($trash_ignore_subscriber) && $container->hasDefinition($target)) {
      $definitions = $container->getDefinitions();
      // Move the Trash ignore subscriber before 'system.timezone_resolver', so
      // it runs first among priority-299 'kernel.request' listeners.
      $trash_definition = [$trash_ignore_subscriber => $definitions[$trash_ignore_subscriber]];
      unset($definitions[$trash_ignore_subscriber]);
      $pos = array_search($target, array_keys($definitions), TRUE);
      assert(is_int($pos));
      $container->setDefinitions(
        array_slice($definitions, 0, $pos, TRUE) +
        $trash_definition +
        array_slice($definitions, $pos, NULL, TRUE)
      );
    }
  }

}
