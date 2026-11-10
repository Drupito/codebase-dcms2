<?php

declare(strict_types=1);

namespace Drupal\trash\PathAlias;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\path\Plugin\Field\FieldType\PathFieldItemList;
use Drupal\pathauto\PathautoFieldItemList;
use Drupal\pathauto\PathautoState;
use Drupal\trash\Trash;
use Drupal\trash\TrashStorageInterface;

/**
 * Provides path alias integration for trash handlers.
 *
 * @phpstan-require-extends \Drupal\trash\Handler\DefaultTrashHandler
 */
trait PathAliasIntegrationTrait {

  /**
   * Whether path aliases should be handled for the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if path aliases should be handled, FALSE otherwise.
   */
  protected function shouldHandlePathAliases(EntityInterface $entity): bool {
    if ($entity->getEntityTypeId() === 'path_alias' || !$this->trashManager->isEntityTypeEnabled('path_alias')) {
      return FALSE;
    }

    // Only entity types with a "canonical" or "edit-form" link template may
    // support path aliases.
    $entity_type = $entity->getEntityType();
    return $entity_type->hasLinkTemplate('canonical') || $entity_type->hasLinkTemplate('edit-form');
  }

  /**
   * Automatically deletes associated path aliases when entity is trashed.
   *
   * Mirrors core, which runs the path field's delete() for every translation
   * when an entity is deleted. The path field deletes the aliases of its own
   * language; the default translation also covers language-neutral ones.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being trashed.
   * @param string[] $langcodes
   *   The langcodes of the translations being trashed.
   */
  protected function deleteAssociatedPathAliases(EntityInterface $entity, array $langcodes): void {
    if (!$this->shouldHandlePathAliases($entity)) {
      return;
    }

    foreach ($this->getHandledTranslations($entity, $langcodes) as $translation) {
      foreach ($translation->getFields() as $field) {
        if ($field instanceof PathFieldItemList) {
          $field->delete();
        }
      }
    }
  }

  /**
   * Automatically restores associated path aliases when an entity is restored.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being restored.
   * @param array<string, int|string> $deleted_timestamps
   *   The deletion timestamps of the restored translations, keyed by
   *   langcode.
   */
  protected function restoreAssociatedPathAliases(EntityInterface $entity, array $deleted_timestamps): void {
    if (!$this->shouldHandlePathAliases($entity)) {
      return;
    }

    // Find path aliases deleted at the same time as a restored translation.
    $storage = $this->entityTypeManager->getStorage('path_alias');
    assert($storage instanceof TrashStorageInterface);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('deleted', array_unique(array_values($deleted_timestamps)), 'IN')
      ->condition('path', '/' . $entity->toUrl()->getInternalPath());

    // On a partial restore, only bring back aliases for the restored
    // languages (plus language-neutral ones), so aliases of still-trashed
    // translations do not start resolving again.
    if (Trash::entityHasDeletedTranslations($entity)) {
      $query->condition('langcode', array_merge(array_keys($deleted_timestamps), [
        LanguageInterface::LANGCODE_NOT_SPECIFIED,
        LanguageInterface::LANGCODE_NOT_APPLICABLE,
      ]), 'IN');
    }

    $ids = $query->execute();

    if (!empty($ids)) {
      $path_aliases = $this->trashManager->executeInTrashContext('ignore', function () use ($storage, $ids) {
        return $storage->loadMultiple($ids);
      });
      $storage->restoreFromTrash($path_aliases);
    }
  }

  /**
   * Stops pathauto from regenerating aliases during a trash operation.
   *
   * Trashing or restoring saves the entity; without this, pathauto's save hook
   * would recreate the alias that deleteAssociatedPathAliases() just removed
   * (or create one early during a restore). Setting the pathauto state to SKIP
   * on every affected translation keeps pathauto out of the operation,
   * regardless of how it tracks that state internally.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being trashed or restored.
   * @param string[] $langcodes
   *   The langcodes of the translations being trashed or restored.
   */
  protected function skipPathauto(EntityInterface $entity, array $langcodes): void {
    if (!$this->shouldHandlePathAliases($entity)) {
      return;
    }

    foreach ($this->getHandledTranslations($entity, $langcodes) as $translation) {
      foreach ($translation->getFields() as $field) {
        if ($field instanceof PathautoFieldItemList) {
          $field->first()->set('pathauto', PathautoState::SKIP);
        }
      }
    }
  }

}
