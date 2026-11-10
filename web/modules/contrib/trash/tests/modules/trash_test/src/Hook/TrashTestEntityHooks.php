<?php

declare(strict_types=1);

namespace Drupal\trash_test\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records newly created translations and replaces their values, for tests.
 */
class TrashTestEntityHooks {

  public function __construct(
    #[Autowire(service: 'keyvalue')]
    protected KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * Implements hook_entity_translation_create().
   */
  #[Hook('entity_translation_create')]
  public function entityTranslationCreate(EntityInterface $translation): void {
    $store = $this->keyValueFactory->get('trash_test');

    // Armed by 'record_translation_create', so a test can assert which
    // langcodes the hook ran for, and that it did not run at all.
    if ($store->get('record_translation_create')) {
      $calls = $store->get('translation_create_calls', []);
      $calls[] = $translation->language()->getId();
      $store->set('translation_create_calls', $calls);
    }

    // Armed by 'translation_create_label', which replaces the label the way
    // paragraphs_entity_translation_create() replaces referenced paragraphs.
    $label = $store->get('translation_create_label');
    if ($label !== NULL && $translation instanceof FieldableEntityInterface && $translation->hasField('label')) {
      $translation->set('label', $label);
    }
  }

}
