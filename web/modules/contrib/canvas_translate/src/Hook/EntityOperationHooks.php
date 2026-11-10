<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\ExtensionUrl;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Makes the operations on a page translation's row edit that translation.
 *
 * Listings like the pages view render one row per translation, but the Canvas
 * editor (the "Edit" operation) always opens the original: it strips language
 * prefixes. So a translation row gets "Edit translation" into this module's
 * editor as its primary operation, and "Edit" says what it really opens.
 */
final class EntityOperationHooks {

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_entity_operation().
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    if (!self::isPageTranslation($entity)) {
      return [];
    }
    $cacheability?->addCacheContexts(['user.permissions']);
    if (!$this->currentUser->hasPermission('translate canvas content')) {
      return [];
    }
    $access = $entity->access('update', return_as_object: TRUE);
    $cacheability?->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return [];
    }
    return [
      'canvas_translate_edit' => [
        'title' => new TranslatableMarkup('Edit translation'),
        'url' => ExtensionUrl::forPage($entity->id(), $entity->language()->getId()),
        // Before core's "Edit" (10), so it is the dropbutton's primary action.
        'weight' => 0,
      ],
    ];
  }

  /**
   * Implements hook_entity_operation_alter().
   */
  #[Hook('entity_operation_alter')]
  public function entityOperationAlter(array &$operations, EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): void {
    if (self::isPageTranslation($entity) && isset($operations['edit'])) {
      $operations['edit']['title'] = new TranslatableMarkup('Edit original');
    }
  }

  /**
   * Whether the entity is a page translation, not the original.
   */
  private static function isPageTranslation(EntityInterface $entity): bool {
    return $entity instanceof Page && !$entity->isDefaultTranslation();
  }

}
