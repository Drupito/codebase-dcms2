<?php

/**
 * @file
 * PHPStan bootstrap for the trash module.
 *
 * TrashNodeSearch extends a parent chosen at runtime via class_alias(), which
 * PHPStan can't follow from the source file. Recreate the alias here so static
 * analysis resolves the parent and its inherited methods. This mirrors the
 * selection in src/Plugin/Search/TrashNodeSearch.php: search_node's SearchNode
 * from Drupal 11.4 on, core's NodeSearch below it.
 */

declare(strict_types=1);

if (!class_exists('Drupal\trash\Plugin\Search\TrashNodeSearchBase', FALSE)) {
  if (class_exists('Drupal\search_node\Plugin\Search\SearchNode')) {
    class_alias('Drupal\search_node\Plugin\Search\SearchNode', 'Drupal\trash\Plugin\Search\TrashNodeSearchBase');
  }
  elseif (class_exists('Drupal\node\Plugin\Search\NodeSearch')) {
    class_alias('Drupal\node\Plugin\Search\NodeSearch', 'Drupal\trash\Plugin\Search\TrashNodeSearchBase');
  }
}
