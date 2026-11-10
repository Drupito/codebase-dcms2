<?php

declare(strict_types=1);

namespace Drupal\trash\Hook\TrashHandler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\path_alias\PathAliasInterface;
use Drupal\trash\Exception\UnrestorableEntityException;
use Drupal\trash\Handler\DefaultTrashHandler;

/**
 * Trash handler for path alias entities.
 */
class PathAliasTrashHandler extends DefaultTrashHandler {

  /**
   * {@inheritdoc}
   */
  public function validateRestore(EntityInterface $entity): void {
    assert($entity instanceof PathAliasInterface);

    // Check if there's a non-deleted path alias with the same alias.
    $result = $this->entityTypeManager->getStorage('path_alias')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $entity->getAlias(), '=')
      ->condition('langcode', $entity->language()->getId(), '=')
      ->notExists('deleted')
      ->range(0, 1)
      ->execute();

    if ($result) {
      // The message is shown to the user by the restore form.
      // @mago-expect lint:drupal/translated-exception
      throw new UnrestorableEntityException((string) $this->t('Cannot restore path alias: An alias with the path "@alias" already exists.', [
        '@alias' => $entity->getAlias(),
      ]));
    }

    $this->markRestoreValidated($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function preTrashRestore(EntityInterface $entity, array $langcodes): void {
    $this->ensureRestoreValidated($entity);
  }

}
