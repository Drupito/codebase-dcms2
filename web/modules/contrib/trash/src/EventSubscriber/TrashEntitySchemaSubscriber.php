<?php

declare(strict_types=1);

namespace Drupal\trash\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeEventSubscriberTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeListenerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines a class for listening to entity schema changes.
 */
class TrashEntitySchemaSubscriber implements EntityTypeListenerInterface, EventSubscriberInterface {

  use EntityTypeEventSubscriberTrait;

  public function __construct(
    protected TrashManagerInterface $trashManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return static::getEntityTypeEvents();
  }

  /**
   * {@inheritdoc}
   */
  public function onEntityTypeCreate(EntityTypeInterface $entity_type): void {
    // trash.settings can list an entity type before its module is installed,
    // and TrashConfigSubscriber skips entity types that aren't defined, so
    // check the storage here.
    if ($this->trashManager->isEntityTypeEnabled($entity_type) && !$this->trashManager->isEntityTypeSupported($entity_type)) {
      $this->loggerFactory->get('trash')->error("'@entity_type_id' is enabled in trash.settings but its storage does not support Trash; entity queries for this type will fail until it is removed from the 'enabled_entity_types' configuration.", [
        '@entity_type_id' => $entity_type->id(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onEntityTypeDelete(EntityTypeInterface $entity_type): void {
    // Remove the deleted entity type from the Trash settings.
    if ($this->trashManager->isEntityTypeEnabled($entity_type)) {
      $trash_settings = $this->configFactory->getEditable('trash.settings');
      $enabled_entity_types = $trash_settings->get('enabled_entity_types');
      unset($enabled_entity_types[$entity_type->id()]);
      $trash_settings->set('enabled_entity_types', $enabled_entity_types)->save();
    }
  }

}
