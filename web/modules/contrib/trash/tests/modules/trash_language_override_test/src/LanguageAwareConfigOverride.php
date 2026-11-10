<?php

declare(strict_types=1);

namespace Drupal\trash_language_override_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Keys configuration overrides by the negotiated interface language.
 *
 * This mimics \Drupal\domain_config\DomainConfigOverrider, which resolves the
 * current interface language inside getCacheSuffix() so that its per-domain
 * overrides are keyed per language.
 *
 * The practical consequence is that *any* service which resolves configuration
 * in its constructor also triggers interface language negotiation. When the
 * 'language-user-admin' negotiation method is enabled, that negotiation
 * requires the router, so such a service must never be constructed while the
 * router is still being built.
 *
 * @see \Drupal\domain_config\DomainConfigOverrider::getCacheSuffix()
 * @see \Drupal\user\Plugin\LanguageNegotiation\LanguageNegotiationUserAdmin
 */
class LanguageAwareConfigOverride implements ConfigFactoryOverrideInterface {

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    // @phpstan-ignore-next-line globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::languageManager()->getCurrentLanguage()->getId();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
