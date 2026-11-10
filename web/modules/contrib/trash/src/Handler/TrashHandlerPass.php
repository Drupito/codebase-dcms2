<?php

declare(strict_types=1);

namespace Drupal\trash\Handler;

use Drupal\Core\Config\BootstrapConfigStorageFactory;
use Drupal\trash\PathAlias\TrashAliasRepository;
use Drupal\trash\PathAlias\TrashWorkspacesAliasRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Defines a compiler pass to register and configure trash handlers.
 */
class TrashHandlerPass implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    // The config factory might not be ready yet, so we bypass the container by
    // using the bootstrap factory.
    $config_storage = BootstrapConfigStorageFactory::get();
    // This config ignores overrides, so trash config from settings.php won't be
    // taken into account.
    /** @var \Drupal\Core\Config\ImmutableConfig $trash_settings */
    $trash_settings = $config_storage->read('trash.settings');

    $enabled_entity_types = $trash_settings['enabled_entity_types'] ?? [];
    $trash_handlers = $container->findTaggedServiceIds('trash_handler');
    $trash_manager = $container->getDefinition('trash.manager');

    foreach ($trash_handlers as $id => $attributes) {
      $entity_type_id = $attributes[0]['entity_type_id'];
      $is_enabled = isset($enabled_entity_types[$entity_type_id]);

      if ($is_enabled) {
        // Keep track of entity types without a dedicated trash handler so we
        // can create one for them automatically.
        unset($enabled_entity_types[$entity_type_id]);
      }

      $container->getDefinition($id)
        ->addMethodCall('setEntityTypeId', [$entity_type_id])
        ->setConfigurator(new Reference(TrashHandlerConfigurator::class));

      // Only register the handler with the trash manager when trash is enabled
      // for its entity type. Registering all of them makes 'trash.manager'
      // instantiate every handler and everything those handlers depend on,
      // which isn't ideal because 'trash.manager' is reachable from bootstrap
      // services like 'path_alias.repository'.
      //
      // The handler definitions are still fully configured,
      // TrashManager::getHandler() just returns NULL for entity types without
      // a registered handler.
      if ($is_enabled) {
        $trash_manager->addMethodCall('addHandler', [new Reference($id), $entity_type_id]);
      }
    }

    // Register a trash handler for entity types without a dedicated one.
    foreach (array_keys($enabled_entity_types) as $entity_type_id) {
      $default_id = 'trash.default_handler.' . $entity_type_id;
      $container->register($default_id, DefaultTrashHandler::class)
        ->addTag('trash_handler', ['entity_type_id' => $entity_type_id])
        ->addMethodCall('setEntityTypeId', [$entity_type_id])
        ->setConfigurator(new Reference(TrashHandlerConfigurator::class));

      $trash_manager->addMethodCall('addHandler', [new Reference($default_id), $entity_type_id]);
    }

    // Replace the class of the 'path_alias.repository' service. We have to do
    // it here so it gets executed after Workspaces' service provider.
    if ($container->hasDefinition('path_alias.repository')) {
      $definition = $container->getDefinition('path_alias.repository');
      // Use the workspaces-aware version if workspaces module is enabled.
      if ($container->hasDefinition('workspaces.manager')) {
        $definition
          ->setClass(TrashWorkspacesAliasRepository::class)
          ->addMethodCall('setWorkspacesManager', [new Reference('workspaces.manager')])
          ->addMethodCall('setTrashManager', [new Reference('trash.manager')]);
      }
      else {
        $definition
          ->setClass(TrashAliasRepository::class)
          ->addMethodCall('setTrashManager', [new Reference('trash.manager')]);
      }
    }
  }

}
