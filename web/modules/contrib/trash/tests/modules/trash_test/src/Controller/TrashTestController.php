<?php

declare(strict_types=1);

namespace Drupal\trash_test\Controller;

use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides test routes for trash.
 */
class TrashTestController {

  /**
   * Deletes a node from a plain POST route, outside of any trash route.
   */
  public function deleteNode(NodeInterface $node): JsonResponse {
    $node->delete();
    return new JsonResponse(['id' => $node->id()]);
  }

}
