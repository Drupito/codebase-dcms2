<?php

declare(strict_types=1);

namespace Drupal\trash\Hook\TrashHandler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\trash\Handler\DefaultTrashHandler;
use Drupal\trash\Trash;
use Drupal\workspaces\Event\WorkspacePostPublishEvent;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Provides a trash handler for the 'menu_link_content' entity type.
 */
class MenuLinkContentTrashHandler extends DefaultTrashHandler implements EventSubscriberInterface {

  public function __construct(
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Implements hook_entity_predelete().
   *
   * Purges cascade-trashed menu links when their target entity is hard-deleted.
   *
   * Core's MenuLinkContentHooks::entityPredelete() finds links via the menu
   * tree, but cascade-trashed links have already been removed from the tree.
   * This hook finds them via entity storage instead.
   */
  #[Hook('entity_predelete')]
  public function entityPredelete(EntityInterface $entity): void {
    if ($this->trashManager->getTrashContext() === 'active') {
      return;
    }

    if ($entity->getEntityTypeId() === 'menu_link_content') {
      return;
    }

    if (!$this->trashManager->isEntityTypeEnabled('menu_link_content')) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $menu_links = $storage->loadByProperties([
      'link.uri' => 'entity:' . $entity->getEntityTypeId() . '/' . $entity->id(),
    ]);

    if (!empty($menu_links)) {
      $storage->delete($menu_links);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postTrashDelete(EntityInterface $entity, array $langcodes): void {
    parent::postTrashDelete($entity, $langcodes);

    // The menu_tree entry covers all languages of the link, so it is only
    // removed once no live translation remains.
    if (!Trash::entityHasAllTranslationsDeleted($entity)) {
      return;
    }

    // This needs to happen *after* the menu link is trashed, so its menu_tree
    // entry can be deleted.
    assert($entity instanceof MenuLinkContentInterface);
    $entity::preDelete($this->entityTypeManager->getStorage('menu_link_content'), [$entity]);
  }

  /**
   * Removes menu link definitions for trashed menu items.
   */
  public function onPostPublish(WorkspacePostPublishEvent $event): void {
    $menu_link_ids = $event->getPublishedRevisionIds()['menu_link_content'] ?? [];
    if (empty($menu_link_ids)) {
      return;
    }

    // Handle menu links that were trashed in the workspace.
    $this->trashManager->executeInTrashContext('ignore', function () use ($menu_link_ids) {
      $this->workspaceManager?->executeOutsideWorkspace(function () use ($menu_link_ids) {
        /** @var \Drupal\menu_link_content\MenuLinkContentInterface[] $menu_links */
        $menu_links = $this->entityTypeManager
          ->getStorage('menu_link_content')
          ->loadMultipleRevisions(array_keys($menu_link_ids));

        foreach ($menu_links as $menu_link) {
          if (Trash::entityIsDeleted($menu_link)) {
            // The menu link manager's dependency chain can reach trash.manager,
            // for example through a decorated menu tree storage, so it cannot
            // be constructor-injected without a container cycle. It also cannot
            // be stored as a lazy closure property, since that breaks
            // serializing this handler.
            // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
            \Drupal::service('plugin.manager.menu.link')->removeDefinition($menu_link->getPluginId(), FALSE);
          }
        }
      });
    });
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    if (class_exists(WorkspacePostPublishEvent::class)) {
      // During workspace publishing, menu link definitions for trashed items
      // could be created or updated, so this subscriber needs to run last in
      // order to remove them.
      $events[WorkspacePostPublishEvent::class][] = ['onPostPublish', -100];
    }

    return $events;
  }

}
