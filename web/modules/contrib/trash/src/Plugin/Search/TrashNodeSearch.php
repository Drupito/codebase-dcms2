<?php

declare(strict_types=1);

namespace Drupal\trash\Plugin\Search;

use Drupal\trash\TrashManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// NodeSearch is removed in Drupal 12; its replacement, search_node's
// SearchNode, only exists from Drupal 11.4 onward. Extend whichever is
// loadable so this class works across ^11.3.3 | ^12 without raising the
// floor to 11.4.
if (class_exists('Drupal\search_node\Plugin\Search\SearchNode')) {
  class_alias('Drupal\search_node\Plugin\Search\SearchNode', 'Drupal\trash\Plugin\Search\TrashNodeSearchBase');
}
else {
  class_alias('Drupal\node\Plugin\Search\NodeSearch', 'Drupal\trash\Plugin\Search\TrashNodeSearchBase');
}

/**
 * A node_search plugin that runs indexing in the 'ignore' trash context.
 *
 * Without this, trash-aware storage hides trashed nodes from loadMultiple(),
 * stalling the indexer on IDs it cannot load. Trashed nodes are indexed like
 * unpublished ones and filtered at search time by NodeTrashHandler.
 */
class TrashNodeSearch extends TrashNodeSearchBase {

  /**
   * The trash manager.
   */
  protected TrashManagerInterface $trashManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create(...func_get_args());
    $instance->trashManager = $container->get(TrashManagerInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(): void {
    $this->trashManager->executeInTrashContext(
      'ignore',
      fn () => parent::updateIndex(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function indexStatus(): array {
    return $this->trashManager->executeInTrashContext(
      'ignore',
      fn () => parent::indexStatus(),
    );
  }

}
