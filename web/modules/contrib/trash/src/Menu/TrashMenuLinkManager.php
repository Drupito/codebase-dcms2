<?php

declare(strict_types=1);

namespace Drupal\trash\Menu;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;

/**
 * Stops trashed menu links from being added back to the menu tree.
 *
 * Both addDefinition() and updateDefinition() return NULL for a trashed link,
 * where MenuLinkManagerInterface documents a MenuLinkInterface. No caller
 * reachable with a 'menu_link_content:' ID uses that return value.
 * MenuLinkContent::postSave() ignores it, and the one caller that returns it,
 * MenuLinkDefaultForm, only handles module-provided links.
 *
 * @internal
 */
class TrashMenuLinkManager implements MenuLinkManagerInterface {

  /**
   * The plugin ID prefix used by menu_link_content links.
   */
  protected const PLUGIN_ID_PREFIX = 'menu_link_content:';

  /**
   * The trash manager.
   */
  protected ?TrashManagerInterface $trashManager = NULL;

  /**
   * The inner manager owns the plugin cache, so this decorator sets no cache.
   *
   * @phpstan-ignore pluginManagerSetsCacheBackend.missingCacheBackend
   */
  public function __construct(
    protected readonly MenuLinkManagerInterface $inner,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Gets the trash manager.
   *
   * Resolved on demand instead of injected. The menu link manager is built
   * during router rebuild and on every menu link save, and 'trash.manager'
   * instantiates every enabled trash handler and whatever those handlers need,
   * so a constructor argument would build all of that every time.
   *
   * @see \Drupal\trash\Hook\TrashHandler\MenuLinkContentTrashHandler::onPostPublish()
   */
  protected function getTrashManager(): TrashManagerInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return $this->trashManager ??= \Drupal::service('trash.manager');
  }

  /**
   * {@inheritdoc}
   */
  public function addDefinition($id, array $definition) {
    if ($this->isTrashedMenuLink((string) $id, $definition)) {
      // No cleanup here, unlike updateDefinition(). MenuLinkContent::postSave()
      // only calls this when the link has no tree row, and core's
      // addDefinition() throws when one already exists.
      // @phpstan-ignore return.type
      return NULL;
    }

    return $this->inner->addDefinition($id, $definition);
  }

  /**
   * {@inheritdoc}
   */
  public function updateDefinition($id, array $new_definition_values, $persist = TRUE) {
    if ($this->isTrashedMenuLink((string) $id, $new_definition_values)) {
      // Reaching this means a tree row already exists for a trashed link, so an
      // earlier save left one behind. Drop it instead of refreshing it.
      if ($this->inner->getDefinition($id, FALSE)) {
        $this->inner->removeDefinition($id, FALSE);
      }
      // @phpstan-ignore return.type
      return NULL;
    }

    return $this->inner->updateDefinition($id, $new_definition_values, $persist);
  }

  /**
   * {@inheritdoc}
   */
  public function rebuild() {
    // A rebuild collects its definitions inside the inner manager, out of
    // reach of the guards above, so the whole rebuild runs in the 'active'
    // trash context instead. MenuLinkContentDeriver's entity query then only
    // returns links with at least one live translation, the same rule
    // MenuLinkContentTrashHandler::postTrashDelete() applies, and the rebuild
    // purges any stale 'discovered' row an unguarded rebuild left behind. The
    // forced context also protects a rebuild in the 'inactive' context, whose
    // inverted entity query filter would otherwise drop every live link from
    // discovery and purge their rows.
    $this->getTrashManager()->executeInTrashContext('active', fn () => $this->inner->rebuild());
  }

  /**
   * {@inheritdoc}
   */
  public function deleteLinksInMenu($menu_name) {
    $this->inner->deleteLinksInMenu($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function removeDefinition($id, $persist = TRUE) {
    $this->inner->removeDefinition($id, $persist);
  }

  /**
   * {@inheritdoc}
   */
  public function loadLinksByRoute($route_name, array $route_parameters = [], $menu_name = NULL) {
    return $this->inner->loadLinksByRoute($route_name, $route_parameters, $menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function resetLink($id) {
    return $this->inner->resetLink($id);
  }

  /**
   * {@inheritdoc}
   */
  public function countMenuLinks($menu_name = NULL) {
    return $this->inner->countMenuLinks($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getParentIds($id) {
    return $this->inner->getParentIds($id);
  }

  /**
   * {@inheritdoc}
   */
  public function getChildIds($id) {
    return $this->inner->getChildIds($id);
  }

  /**
   * {@inheritdoc}
   */
  public function menuNameInUse($menu_name) {
    return $this->inner->menuNameInUse($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function resetDefinitions() {
    $this->inner->resetDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return $this->inner->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return $this->inner->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return $this->inner->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    return $this->inner->createInstance($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return $this->inner->getInstance($options);
  }

  /**
   * Checks whether a menu link definition belongs to a trashed entity.
   *
   * @param string $id
   *   The menu link plugin ID.
   * @param array $definition
   *   The definition being saved, used to avoid a UUID lookup when it has the
   *   entity ID.
   *
   * @return bool
   *   TRUE if the link is backed by a menu_link_content entity with no live
   *   translation left.
   */
  protected function isTrashedMenuLink(string $id, array $definition): bool {
    // Outside the 'active' context storage stops filtering trashed entities, so
    // a save can put one back into the tree. In the 'active' context a trashed
    // link can not be loaded at all, and the save that trash itself performs
    // while soft-deleting is cleaned up by
    // MenuLinkContentTrashHandler::postTrashDelete(). Bailing out here keeps
    // ordinary menu link saves free of the lookup below.
    if (!str_starts_with($id, static::PLUGIN_ID_PREFIX)
      || $this->getTrashManager()->getTrashContext() === 'active'
      || !$this->getTrashManager()->isEntityTypeEnabled('menu_link_content')
    ) {
      return FALSE;
    }

    // Storage does not filter trashed entities outside the 'active' context, so
    // this needs no trash context switch, which would clear static entity
    // caches.
    $entity = !empty($definition['metadata']['entity_id'])
      ? $this->entityTypeManager->getStorage('menu_link_content')->load($definition['metadata']['entity_id'])
      : $this->entityRepository->loadEntityByUuid('menu_link_content', substr($id, strlen(static::PLUGIN_ID_PREFIX)));

    // A menu_tree row covers every language of a link, so the link only leaves
    // the tree once no live translation is left. Checking the default
    // translation is enough. Trash never leaves a live translation beside a
    // deleted default one, so a deleted default means every translation is
    // deleted. Both loads above return the default translation.
    // @see \Drupal\trash\TrashStorageTrait::restoreFromTrash()
    // @see \Drupal\trash\Hook\TrashHandler\MenuLinkContentTrashHandler::postTrashDelete()
    return $entity && Trash::entityIsDeleted($entity);
  }

}
