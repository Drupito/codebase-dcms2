<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Hook;

use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas_translate\Service\PageRegionTranslationUpgrade;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Moves page region translations when Canvas turns regions into a variant.
 */
final class PageVariantUpgradeHooks {

  public function __construct(
    private readonly PageRegionTranslationUpgrade $upgrade,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for page_variant.
   *
   * Canvas creates a "theme_<theme>" variant when it converts a theme's page
   * regions: in its own update hook, or later when Canvas is enabled for a
   * theme on the theme settings form. Move the regions' drafts, approvals and
   * published translations over right away, so nothing is left behind on the
   * regions.
   *
   * A variant created by a config import is left alone: its translations come
   * from the export.
   *
   * @see \Drupal\canvas\PageVariantMigration::createFromPageRegions()
   */
  #[Hook('page_variant_insert')]
  public function pageVariantInsert(EntityInterface $entity): void {
    if ($entity instanceof PageVariant && !$entity->isSyncing() && PageRegionTranslationUpgrade::isThemeVariant($entity)) {
      $this->upgrade->upgrade($entity);
    }
  }

}
