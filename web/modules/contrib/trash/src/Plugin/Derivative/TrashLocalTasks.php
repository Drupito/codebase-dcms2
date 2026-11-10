<?php

declare(strict_types=1);

namespace Drupal\trash\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowiredInstanceTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\trash\Plugin\Menu\TrashOperationLocalTask;
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides local task definitions for trash-enabled entity types.
 */
class TrashLocalTasks extends DeriverBase implements ContainerDeriverInterface {

  use AutowiredInstanceTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TrashManagerInterface $trashManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return static::createInstanceAutowired($container);
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    if (isset($base_plugin_definition['trash_operation'])) {
      return $this->getEntityOperationDefinitions($base_plugin_definition);
    }

    if ($this->configFactory->get('trash.settings')->get('compact_overview')) {
      return ['trash' => $base_plugin_definition];
    }

    $this->derivatives = [];
    $enabled_entity_types = $this->trashManager->getEnabledEntityTypes();

    if (!$enabled_entity_types) {
      return $this->derivatives;
    }

    $default_entity_type = in_array('node', $enabled_entity_types, TRUE) ? 'node' : reset($enabled_entity_types);

    foreach ($enabled_entity_types as $entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

      $this->derivatives[$entity_type_id] = $base_plugin_definition;
      $this->derivatives[$entity_type_id]['title'] = $entity_type->getCollectionLabel();
      $this->derivatives[$entity_type_id]['route_parameters'] = ['entity_type_id' => $entity_type_id];
      $this->derivatives[$entity_type_id]['cache_tags'] = ['config:trash.settings'];

      // Default task.
      if ($default_entity_type === $entity_type_id) {
        $this->derivatives[$entity_type_id]['route_name'] = $base_plugin_definition['parent_id'];
        // Emulate default logic because without the base plugin id we can't
        // change the base_route.
        $this->derivatives[$entity_type_id]['weight'] = -10;

        unset($this->derivatives[$entity_type_id]['route_parameters']);
      }
    }

    return $this->derivatives;
  }

  /**
   * Provides local task definitions for a trash entity operation.
   *
   * The 'restore' and 'purge' routes are added for each trash-enabled entity
   * type, so the tasks are placed next to the other tabs of the entity, where
   * they are shown while the entity is viewed in the trash context.
   *
   * @param array $base_plugin_definition
   *   The base plugin definition, with the operation in 'trash_operation'.
   *
   * @return array
   *   An array of local task definitions, keyed by entity type ID.
   *
   * @see \Drupal\trash\Routing\RouteSubscriber::alterRoutes()
   * @see \Drupal\trash\RouteProcessor\TrashRouteProcessor::processOutbound()
   */
  protected function getEntityOperationDefinitions(array $base_plugin_definition): array {
    $this->derivatives = [];
    $operation = $base_plugin_definition['trash_operation'];

    foreach ($this->trashManager->getEnabledEntityTypes() as $entity_type_id) {
      // The settings can name an entity type whose module was uninstalled.
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        continue;
      }
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

      // Entity types without a canonical link template have no page to put the
      // tasks on, their operations are only available in the trash listing.
      if (!$entity_type->hasLinkTemplate('canonical')) {
        continue;
      }

      $this->derivatives[$entity_type_id] = $base_plugin_definition;
      $this->derivatives[$entity_type_id]['class'] = TrashOperationLocalTask::class;
      $this->derivatives[$entity_type_id]['route_name'] = "entity.$entity_type_id.$operation";
      $this->derivatives[$entity_type_id]['base_route'] = "entity.$entity_type_id.canonical";
      $this->derivatives[$entity_type_id]['trash_entity_type_id'] = $entity_type_id;
      $this->derivatives[$entity_type_id]['cache_tags'] = ['config:trash.settings'];
    }

    return $this->derivatives;
  }

}
