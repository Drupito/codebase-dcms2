<?php

declare(strict_types=1);

namespace Drupal\trash_test\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records trash lifecycle hook invocations for tests.
 *
 * Recording is armed by setting 'record_trash_hooks' to TRUE in the
 * 'trash_test' key-value collection. Each invocation is appended to the
 * 'trash_hook_calls' entry with the hook name, the entity type ID and ID,
 * and the langcodes or timestamps context the hook received.
 */
class TrashTestTrashHooks {

  public function __construct(
    #[Autowire(service: 'keyvalue')]
    protected KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * Implements hook_entity_pre_trash_delete().
   */
  #[Hook('entity_pre_trash_delete')]
  public function entityPreTrashDelete(EntityInterface $entity, array $langcodes): void {
    $this->record('entity_pre_trash_delete', $entity, $langcodes);
  }

  /**
   * Implements hook_entity_trash_delete().
   */
  #[Hook('entity_trash_delete')]
  public function entityTrashDelete(EntityInterface $entity, array $langcodes): void {
    $this->record('entity_trash_delete', $entity, $langcodes);
  }

  /**
   * Implements hook_entity_pre_trash_restore().
   */
  #[Hook('entity_pre_trash_restore')]
  public function entityPreTrashRestore(EntityInterface $entity, array $langcodes): void {
    $this->record('entity_pre_trash_restore', $entity, $langcodes);
  }

  /**
   * Implements hook_entity_trash_restore().
   */
  #[Hook('entity_trash_restore')]
  public function entityTrashRestore(EntityInterface $entity, array $deleted_timestamps): void {
    $this->record('entity_trash_restore', $entity, $deleted_timestamps);
  }

  /**
   * Appends a hook invocation to the log when recording is armed.
   */
  protected function record(string $hook, EntityInterface $entity, array $context): void {
    $store = $this->keyValueFactory->get('trash_test');
    if (!$store->get('record_trash_hooks')) {
      return;
    }
    $calls = $store->get('trash_hook_calls', []);
    $calls[] = [
      'hook' => $hook,
      'entity_type_id' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'context' => $context,
    ];
    $store->set('trash_hook_calls', $calls);
  }

}
