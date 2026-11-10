<?php

declare(strict_types=1);

namespace Drupal\trash\MenuLinkContent;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\trash\Trash;
use Drupal\trash\TrashStorageInterface;

/**
 * Provides menu link content integration for trash handlers.
 *
 * When an entity is trashed, menu links pointing to it become broken: the
 * route access check cannot load the entity, producing an uncacheable
 * AccessResult (max-age=0) that poisons the entire menu block render cache.
 *
 * @phpstan-require-extends \Drupal\trash\Handler\DefaultTrashHandler
 */
trait MenuLinkContentIntegrationTrait {

  /**
   * Whether menu link content entities should be handled for the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if menu link content entities should be handled, FALSE otherwise.
   */
  protected function shouldHandleMenuLinkContent(EntityInterface $entity): bool {
    return $entity->getEntityTypeId() !== 'menu_link_content'
      && $this->trashManager->isEntityTypeEnabled('menu_link_content');
  }

  /**
   * Soft-deletes menu link content entities pointing to the trashed entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being trashed.
   */
  protected function deleteAssociatedMenuLinkContent(EntityInterface $entity): void {
    if (!$this->shouldHandleMenuLinkContent($entity)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $menu_links = $storage->loadByProperties([
      'link.uri' => 'entity:' . $entity->getEntityTypeId() . '/' . $entity->id(),
    ]);

    if (!empty($menu_links)) {
      $storage->delete($menu_links);
    }
  }

  /**
   * Restores menu link content entities that were soft-deleted with the entity.
   *
   * A menu link is a single entity covering all languages of its target and is
   * trashed as one unit only once the target is fully deleted, so it comes back
   * when the target's default translation is restored, which is when the target
   * is a live node again. The link was marked with the target's full-delete
   * time, which is the timestamp its default translation had in trash.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being restored.
   * @param array<string, int|string> $deleted_timestamps
   *   The deletion timestamps of the restored translations, keyed by
   *   langcode.
   */
  protected function restoreAssociatedMenuLinkContent(EntityInterface $entity, array $deleted_timestamps): void {
    if (!$this->shouldHandleMenuLinkContent($entity)) {
      return;
    }

    $default_langcode = $entity instanceof TranslatableInterface
      ? $entity->getUntranslated()->language()->getId()
      : $entity->language()->getId();
    if (!isset($deleted_timestamps[$default_langcode])) {
      return;
    }
    $cascade_timestamp = $deleted_timestamps[$default_langcode];

    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('deleted', $cascade_timestamp)
      ->condition('link.uri', 'entity:' . $entity->getEntityTypeId() . '/' . $entity->id())
      ->execute();

    if (empty($ids)) {
      return;
    }

    $menu_links = $this->trashManager->executeInTrashContext('ignore', function () use ($storage, $ids) {
      return $storage->loadMultiple($ids);
    });
    assert($storage instanceof TrashStorageInterface);
    foreach ($menu_links as $menu_link) {
      // Restore only the translations the cascade trashed, the ones marked
      // with the cascade timestamp. Translations trashed on their own keep a
      // different timestamp and stay in the trash.
      $langcodes = [];
      foreach (array_keys($menu_link->getTranslationLanguages()) as $langcode) {
        $translation = $menu_link->getTranslation($langcode);
        if (Trash::entityIsDeleted($translation) && $translation->get('deleted')->value == $cascade_timestamp) {
          $langcodes[] = $langcode;
        }
      }
      $storage->restoreFromTrash([$menu_link], $langcodes);
    }
  }

}
