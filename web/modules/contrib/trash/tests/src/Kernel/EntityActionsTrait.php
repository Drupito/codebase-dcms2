<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Tests\RandomGeneratorTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;
use Drupal\user\Entity\User;

/**
 * Provides standardized entity related actions for tests.
 *
 * @see \Drupal\Tests\trash\Kernel\EntityAccessTestTrait
 *
 * @phpstan-require-extends \Drupal\Tests\trash\Kernel\TrashKernelTestBase
 */
trait EntityActionsTrait {

  use RandomGeneratorTrait;
  use UserCreationTrait;

  /**
   * Creates an entity with a given type and values.
   *
   * @param string $entity_type_id
   *   The type of entity to create.
   * @param array<string, mixed> $values
   *   The field/property values to initialize the entity with.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created entity.
   */
  protected function createEntity(
    string $entity_type_id,
    array $values = [],
  ): EntityInterface {
    $definition = $this->getEntityTypeManager()->getDefinition($entity_type_id);

    $label_key = $definition->getKey('label');
    if ($label_key && !isset($values[$label_key])
    ) {
      $values[$label_key] = $this->randomString();
    }

    $owner_key = $definition->getKey('owner');
    if ($owner_key && !isset($values[$owner_key])) {
      $user = User::load(\Drupal::currentUser()->id());
      if ($user) {
        $uid = $user->id();
      }
      elseif (method_exists($this, 'setUpCurrentUser')) {
        /** @var \Drupal\user\UserInterface $user */
        $user = $this->setUpCurrentUser();
        $uid = $user->id();
      }
      else {
        $uid = 0;
      }
      $values[$owner_key] = $uid;
    }
    $entity = $this->getEntityTypeManager()->getStorage($entity_type_id)->create($values);
    $entity->save();
    return $entity;
  }

  /**
   * Performs an action on the entity in the context.
   *
   * @param string|array $action
   *   The name of an action, or an array with the name and arguments.
   * @param array $context
   *   The current context of the test.
   *   'entity' is assumed to be the entity to perform actions on.
   */
  protected function performAction(string|array $action, array &$context): void {
    $arguments = is_array($action) && isset($action[1]) ? $action[1] : NULL;
    $action = is_array($action) ? $action[0] : $action;
    assert(is_string($action));

    $entity = $context['entity'];
    assert($entity instanceof EntityInterface);

    switch ($action) {
      case 'delete':
        $entity->delete();
        // References are stale after delete.
        $context['entity'] = $this->forceLoadEntity(
          $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId()),
          $entity->id(),
          $entity->language()->getId()
        );
        break;

      case 'restore':
        Trash::restoreEntity($entity);
        // References are stale after restore.
        $context['entity'] = $this->forceLoadEntity(
          $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId()),
          $entity->id(),
          $entity->language()->getId()
        );
        break;

      case 'load-revision':
        assert($entity instanceof RevisionableInterface);
        $storage = $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId());
        assert($storage instanceof RevisionableStorageInterface);
        $context['entity'] = $storage->loadRevision($arguments);
        break;

      case 'load-nth-revision':
        assert($entity instanceof RevisionableInterface);
        $entity_type = $this->getEntityTypeManager()->getDefinition($entity->getEntityTypeId());
        $revision_ids = $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId())
          ->getQuery()
          ->accessCheck(FALSE)
          ->allRevisions()
          ->condition($entity_type->getKey('id'), $entity->id())
          ->sort($entity_type->getKey('revision'))
          ->execute();
        $revision_id = array_values(array_keys($revision_ids))[$arguments - 1] ?? NULL;
        assert($revision_id !== NULL, "Revision #$arguments not found.");
        $storage = $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId());
        assert($storage instanceof RevisionableStorageInterface);
        $context['entity'] = $storage->loadRevision($revision_id);
        break;

      case 'reload-entity':
        $context['entity'] = $this->forceLoadEntity(
          $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId()),
          $entity->id(),
          $entity->language()->getId()
        );
        break;

      case 'create-revision':
        assert($entity instanceof RevisionableInterface);
        $storage = $this->getEntityTypeManager()->getStorage($entity->getEntityTypeId());
        assert($storage instanceof RevisionableStorageInterface);
        $context['entity'] = $storage->createRevision($entity, $arguments);
        break;

      case 'load-active':
        $repository = $this->getEntityRepository();
        $context['entity'] = $repository->getActive($entity->getEntityTypeId(), $entity->id(), $arguments);
        break;

      case 'load-canonical':
        $repository = $this->getEntityRepository();
        $context['entity'] = $repository->getCanonical($entity->getEntityTypeId(), $entity->id(), $arguments);
        break;

      case 'set-values':
        assert($entity instanceof FieldableEntityInterface);
        foreach ($arguments as $field => $value) {
          $entity->set($field, $value);
        }
        break;

      case 'save':
        $entity->save();
        break;

      case 'add-translation':
        assert($entity instanceof TranslatableInterface);
        $entity->addTranslation(...$arguments);
        $entity->save();
        break;

      case 'delete-translation':
        assert($entity instanceof TranslatableInterface);
        $langcodes = is_array($arguments) ? $arguments : [$arguments];

        foreach ($langcodes as $langcode) {
          $entity->removeTranslation($langcode);
        }

        $entity->save();
        break;

      case 'restore-translation':
        Trash::restoreEntity($entity, $arguments);
        break;

      case 'switch-translation':
        assert($entity instanceof TranslatableInterface);
        $context['entity'] = $entity->getTranslation($arguments);
        break;

      case 'set-trash-context':
        $this->getTrashManager()->setTrashContext($arguments);
        break;

      case 'assert-operations-access':
        foreach ($arguments as $operation => $expected) {
          if ($expected instanceof AccessResultInterface) {
            $actual = $entity->access($operation, NULL, TRUE);
            $this->assertInstanceOf($expected::class, $actual, "Unexpected '$operation' access result type.");
            if ($expected instanceof AccessResultReasonInterface && $expected->getReason() !== NULL) {
              assert($actual instanceof AccessResultReasonInterface);
              $this->assertSame($expected->getReason(), $actual->getReason(), "Unexpected '$operation' access reason.");
            }
          }
          else {
            $this->assertEquals($expected, $entity->access($operation), sprintf("Expected '$operation' operation access to equal %s.", $expected ? 'TRUE' : 'FALSE'));
          }
        }
        break;

      case 'assert-values':
        foreach ($arguments as $field => $value) {
          assert($entity instanceof FieldableEntityInterface);
          $actual = $entity->get($field)->getValue();
          $this->assertEquals($value, $actual, \sprintf('Expected "%s" value "%s" to match: "%s".', $field, json_encode($actual), json_encode($value)));
        }
        break;

      default:
        throw new \Exception("Unknown action: $action");
    }
  }

  /**
   * Loads an entity in the 'ignored' trash context.
   *
   * @see \Drupal\Tests\trash\Kernel\TrashKernelTestBase::forceLoadEntity()
   */
  abstract protected function forceLoadEntity(EntityStorageInterface $storage, string $id, ?string $langcode = NULL, ?string $revision_id = NULL): EntityInterface|ContentEntityInterface|null;

  /**
   * Gets the trash manager.
   */
  abstract protected function getTrashManager(): TrashManagerInterface;

  /**
   * Gets the entity type manager.
   */
  abstract protected function getEntityTypeManager(): EntityTypeManagerInterface;

  /**
   * Gets the entity repository.
   */
  abstract protected function getEntityRepository(): EntityRepositoryInterface;

}
