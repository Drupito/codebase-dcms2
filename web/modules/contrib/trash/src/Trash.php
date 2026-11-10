<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;

/**
 * Provides static helpers for working with trashed entities.
 */
final class Trash {

  /**
   * Determines whether an entity is deleted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   *
   * @return bool
   *   TRUE if the entity is deleted, FALSE otherwise.
   */
  public static function entityIsDeleted(EntityInterface $entity): bool {
    if (!$entity instanceof FieldableEntityInterface) {
      return FALSE;
    }

    return $entity->getFieldDefinition('deleted')?->getFieldStorageDefinition()->getProvider() === 'trash'
      && !$entity->get('deleted')->isEmpty();
  }

  /**
   * Determines whether any translation of an entity is deleted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   *
   * @return bool
   *   TRUE if at least one translation is deleted, FALSE otherwise.
   */
  public static function entityHasDeletedTranslations(EntityInterface $entity): bool {
    if (!$entity instanceof TranslatableInterface || !$entity->getEntityType()->isTranslatable()) {
      return static::entityIsDeleted($entity);
    }

    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      if (static::entityIsDeleted($entity->getTranslation($langcode))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Determines whether every translation of an entity is deleted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   *
   * @return bool
   *   TRUE if all translations are deleted, FALSE otherwise.
   */
  public static function entityHasAllTranslationsDeleted(EntityInterface $entity): bool {
    if (!$entity instanceof TranslatableInterface || !$entity->getEntityType()->isTranslatable()) {
      return static::entityIsDeleted($entity);
    }

    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      if (!static::entityIsDeleted($entity->getTranslation($langcode))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Restores the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   * @param string[] $langcodes
   *   The translations to restore, defaults to all. A deleted default
   *   translation is always restored, even when not requested.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures, an exception is thrown.
   */
  public static function restoreEntity(EntityInterface $entity, array $langcodes = []): void {
    // Check the entity type only, not the bundle, so restore still works after
    // a bundle is dropped from trash.settings.
    if (!\Drupal::service('trash.manager')->isEntityTypeEnabled($entity->getEntityType())) {
      return;
    }

    $storage = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId());
    assert($storage instanceof TrashStorageInterface);
    $storage->restoreFromTrash([$entity], $langcodes);
  }

}
