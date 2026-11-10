<?php

declare(strict_types=1);

namespace Drupal\trash\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\workspaces\WorkspaceInformationInterface;
use Drupal\workspaces\WorkspaceInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drupal\workspaces\WorkspaceTrackerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Decorates the entity cache backend to filter out deleted entities.
 *
 * The workspace services are only passed in when the Workspaces module is
 * installed.
 *
 * @see \Drupal\trash\TrashServiceProvider::alter()
 */
class TrashEntityCacheBackend implements CacheBackendInterface, CacheTagsInvalidatorInterface {

  use TrashCacheBackendTrait;

  /**
   * Whether the active workspace is currently being negotiated.
   */
  private bool $resolvingActiveWorkspace = FALSE;

  public function __construct(
    #[AutowireDecorated]
    protected CacheBackendInterface $inner,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
    protected ?WorkspaceInformationInterface $workspaceInformation = NULL,
    protected ?WorkspaceTrackerInterface $workspaceTracker = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $item = $this->inner->get($cid, $allow_invalid);

    // @todo This should not be needed anymore if core starts using
    //   workspace-specific cache IDs.
    return $item && $this->findStaleItems([$cid => $item]) ? FALSE : $item;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $items = $this->inner->getMultiple($cids, $allow_invalid);

    foreach ($this->findStaleItems($items) as $cid) {
      unset($items[$cid]);
      // Report the entry as a miss, so the caller loads the entity with a
      // storage query, which TrashEntityHooks::queryAlter() filters.
      $cids[] = $cid;
    }

    return $items;
  }

  /**
   * Finds the cached entities that must not be served to the active workspace.
   *
   * The cache is keyed by entity ID, so each entry holds the default (Live)
   * revision. Inside a workspace, hook_entity_preload() swaps in the revision
   * the workspace tracks, and it runs before the cache is read. A cache hit for
   * a tracked ID means the swap did not happen, because Trash filtered the
   * soft-deleted revision out of the preload's ::loadMultipleRevisions() query.
   * That Live revision is neither deleted nor unpublished, so serving it shows
   * an entity that was deleted in this workspace.
   *
   * The entity memory cache needs none of this. It is invalidated per entity
   * type when the active workspace changes, so it only holds revisions loaded
   * inside the current workspace.
   *
   * @param object[] $items
   *   Cache items returned by the decorated backend, keyed by cache ID.
   *
   * @return string[]
   *   The cache IDs to report as a miss.
   */
  private function findStaleItems(array $items): array {
    // Cache entries keyed by revision ID hold pending revisions on purpose, so
    // only default revisions are checked. Entity types that Workspaces does not
    // support are never tracked, so they are filtered out before the active
    // workspace is resolved, which is the more expensive question to ask.
    // @see \Drupal\Core\Entity\ContentEntityStorageBase::setPersistentRevisionCache()
    $entity_ids = [];
    foreach ($items as $cid => $item) {
      $entity = $item->data ?? NULL;
      if ($entity instanceof ContentEntityInterface
        && $entity->isDefaultRevision()
        && $this->workspaceInformation?->isEntityTypeSupported($entity->getEntityType())
      ) {
        $entity_ids[$entity->getEntityTypeId()][$cid] = $entity->id();
      }
    }

    if (!$entity_ids || !$active_workspace = $this->getActiveWorkspace()) {
      return [];
    }

    $stale = [];
    foreach ($entity_ids as $entity_type_id => $cids) {
      // Keyed by revision ID, with entity IDs as values.
      $tracked = $this->workspaceTracker->getTrackedEntities($active_workspace->id(), $entity_type_id, array_values($cids));
      $tracked = array_flip($tracked[$entity_type_id] ?? []);

      foreach ($cids as $cid => $entity_id) {
        if (isset($tracked[$entity_id])) {
          $stale[] = $cid;
        }
      }
    }

    return $stale;
  }

  /**
   * Returns the active workspace, unless it is still being negotiated.
   *
   * Negotiating the active workspace loads the workspace entity, and checks
   * 'view' access on it, before the negotiated value is stored. Both of those
   * can read this cache, so asking for the active workspace while it is being
   * resolved calls straight back into ::findStaleItems() and recurses. There is
   * no active workspace to compare tracked revisions against yet, so NULL is
   * also the correct answer while that is going on.
   *
   * @see \Drupal\workspaces\WorkspaceManager::getActiveWorkspace()
   */
  private function getActiveWorkspace(): ?WorkspaceInterface {
    if ($this->resolvingActiveWorkspace) {
      return NULL;
    }

    $this->resolvingActiveWorkspace = TRUE;
    try {
      return $this->workspaceManager?->getActiveWorkspace();
    }
    finally {
      $this->resolvingActiveWorkspace = FALSE;
    }
  }

}
