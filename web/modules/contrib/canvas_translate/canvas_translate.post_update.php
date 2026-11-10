<?php

/**
 * @file
 * Post update functions for Canvas Translate.
 */

declare(strict_types=1);

use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas_translate\Service\PageRegionTranslationUpgrade;
use Drupal\Core\Config\ConfigDirectoryNotDefinedException;

/**
 * Gives page variants the UUID the config export has for them.
 *
 * Each site's Canvas update creates "theme_<theme>" with its own UUID. A config
 * import of another site's variant then deletes and recreates it, and core
 * deletes the variant's published translations with it. With the UUID from the
 * export, the import updates the variant instead.
 *
 * Core does not allow changing the UUID of a saved config entity, so the config
 * is written directly. Nothing refers to a page variant by its UUID.
 *
 * @see \Drupal\Core\Config\StorageComparer::addChangelistUpdate()
 * @see \Drupal\Core\Config\Entity\ConfigEntityBase::preSave()
 */
function canvas_translate_post_update_0001_align_page_variant_uuids(): void {
  try {
    $sync = \Drupal::service('config.storage.sync');
  }
  catch (ConfigDirectoryNotDefinedException) {
    return;
  }
  $config_factory = \Drupal::configFactory();
  $prefix = \Drupal::entityTypeManager()->getDefinition(PageVariant::ENTITY_TYPE_ID)->getConfigPrefix();
  foreach ($config_factory->listAll($prefix . '.' . PageRegionTranslationUpgrade::VARIANT_ID_PREFIX) as $name) {
    $exported = $sync->read($name);
    $config = $config_factory->getEditable($name);
    if (\is_array($exported) && isset($exported['uuid']) && $exported['uuid'] !== $config->get('uuid')) {
      $config->set('uuid', $exported['uuid'])->save(TRUE);
    }
  }
  \Drupal::entityTypeManager()->getStorage(PageVariant::ENTITY_TYPE_ID)->resetCache();
}

/**
 * Moves page region translation drafts and approvals to the page variants.
 */
function canvas_translate_post_update_0002_move_page_region_translations(): void {
  \Drupal::service(PageRegionTranslationUpgrade::class)->upgradeAll();
}
