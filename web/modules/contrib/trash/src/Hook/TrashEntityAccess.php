<?php

namespace Drupal\trash\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;
use Drupal\workspaces\WorkspaceInformationInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Handles access checking for an entity based on deleted status.
 *
 * Entity access results are OR:ed together, so if any of the checks pass,
 * and the rest are neutral, access is granted. We are looking to limit
 * otherwise allowed operations, so we need to explicitly forbid access with
 * these checks where operations are illogical or not allowed.
 *
 * We only return neutral results for operations that are not allowed by our
 * permissions but could possibly be allowed by other modules wanting to grant
 * access based on some other criteria.
 *
 * @see \Drupal\trash\EntityHandler\TrashNodeAccessControlHandler
 */
class TrashEntityAccess {

  /**
   * The entity getTranslationAccess() is re-entering entity access for.
   *
   * NULL when no re-entrant 'update' check is in progress.
   */
  protected ?EntityInterface $translationAccessEntity = NULL;

  public function __construct(
    protected TrashManagerInterface $trashManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
    protected ?WorkspaceInformationInterface $innerWorkspaceInformation = NULL,
  ) {}

  /**
   * Implements hook_entity_access().
   *
   * Ensure the correct translation and revision is passed in, based on what you
   * actually want to check.
   */
  #[Hook('entity_access')]
  public function entityAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    $is_trash_operation = in_array($operation, ['restore', 'purge'], TRUE);
    $is_translation = $entity instanceof TranslatableInterface && !$entity->isDefaultTranslation();

    $access = AccessResult::allowed()->addCacheableDependency($entity);

    if ($operation === 'revert revision'
      && $entity instanceof RevisionableInterface
      && !$entity->isDefaultRevision()
      && !$entity->isLatestRevision()
      && Trash::entityIsDeleted($entity)
    ) {
      return $access->andIf(AccessResult::forbidden('Can not revert to a deleted revision'));
    }

    if ($is_trash_operation) {
      // Restore and purge outcomes flip when trash support is enabled or
      // disabled for the entity type, so they depend on the trash settings.
      $access->addCacheableDependency($this->configFactory->get('trash.settings'));
      if (!$this->trashManager->isEntityTypeEnabled($entity->getEntityTypeId())) {
        return $access->andIf(AccessResult::forbidden('Unsupported entity type'));
      }
    }

    if (!Trash::entityIsDeleted($entity)) {
      // If the entity is not deleted, the 'restore' and 'purge' operations
      // should never be allowed. Other operations are not affected.
      return $access->andIf(AccessResult::forbiddenIf($is_trash_operation, "Unsupported operation on non-deleted entity: $operation"));
    }

    $is_view_operation = $operation === 'view' || $operation === 'view label';

    // getTranslationAccess() re-enters entity access to ask whether the
    // account could update the entity if it were not deleted, so the
    // deleted-entity forbid below has to step aside for that one call.
    if ($operation === 'update' && $entity === $this->translationAccessEntity) {
      return $access->andIf(AccessResult::neutral('Checking update access for the translation fallback'));
    }

    // Old deleted revisions of an entity can still be managed with the regular
    // revision permissions while the trash context is 'ignore'. The default
    // revision keeps the deleted-entity forbid below in every context, so a
    // trashed entity cannot be edited or deleted just because the context was
    // switched, for example through the 'in_trash' query parameter.
    if (!$is_trash_operation && !$is_view_operation
      && $entity instanceof RevisionableInterface
      && !$entity->isDefaultRevision()
      && $this->trashManager->getTrashContext() === 'ignore'
    ) {
      return $access->andIf(AccessResult::neutral('Trash context is "ignore" for a non-default revision'));
    }

    $access->addCacheContexts(['user.permissions']);

    // Check if users can view, restore or purge deleted entities.
    if ($is_view_operation) {
      // Only ever remove view access here; let the entity's own access handler
      // decide whether to grant it. Returning allowed() would OR with the
      // handler and over-grant on entity types whose handler returns neutral
      // (rather than forbidden) when a user lacks view access.
      return AccessResult::forbiddenIf(!$account->hasPermission('view deleted entities'))
        ->addCacheableDependency($entity)
        ->cachePerPermissions();
    }

    if ($operation === 'restore') {
      $permission = "restore {$entity->getEntityTypeId()} entities";
      if (!$account->hasPermission($permission)) {
        if (!$is_translation) {
          return $access->andIf(
            AccessResult::forbidden("The '$permission' permission is required.")
          );
        }

        $permission = "restore {$entity->getEntityTypeId()} translations";
        if (!$account->hasPermission($permission)) {
          return $access->andIf(
            AccessResult::forbidden(
              "The '$permission' permission is required."
            )
          );
        }
      }

      if (!$is_translation) {
        return $access;
      }

      // Restoring a translation with a deleted original language would also
      // need to restore the original language.
      if (Trash::entityIsDeleted($entity->getUntranslated())) {
        $access = $access->andIf($entity->getUntranslated()->access('restore', $account, TRUE));
      }

      if (!$this->moduleHandler->moduleExists('content_translation')) {
        return $access;
      }

      return $access->andIf($this->getTranslationAccess($entity, 'update', $account));
    }

    if ($operation === 'purge') {
      $permission = "purge {$entity->getEntityTypeId()} entities";
      if (!$account->hasPermission($permission)) {
        if (!$is_translation) {
          return $access->andIf(
            AccessResult::forbidden("The '$permission' permission is required.")
          );
        }

        $permission = "purge {$entity->getEntityTypeId()} translations";
        if (!$account->hasPermission($permission)) {
          return $access->andIf(
            AccessResult::forbidden("The '$permission' permission is required.")
          );
        }
      }

      // Ensure that trashed entities can only be purged in the workspace they
      // were created in or in Live.
      // Use the service the trash decorator wraps, not the decorated one:
      // purge is a hard delete, so it must not get the decorator's
      // delete-is-harmless override. This gives core's ownership rule no matter
      // how the workspace information service is decorated. The decorator does
      // not override isEntitySupported(), so the inner answers that too.
      if (NULL !== $this->workspaceManager
        && NULL !== $this->innerWorkspaceInformation
        && $this->innerWorkspaceInformation->isEntitySupported($entity)
        && ($active_workspace = $this->workspaceManager->getActiveWorkspace())
      ) {
        $access->addCacheableDependency($active_workspace);

        if (!$this->innerWorkspaceInformation->isEntityDeletable($entity, $active_workspace)) {
          return $access->andIf(
            AccessResult::forbidden(
              'Entity cannot be purged in the current workspace.'
            )
          );
        }
      }

      if ($is_translation) {

        if (!$this->moduleHandler->moduleExists('content_translation')) {
          return $access;
        }

        return $access->andIf($this->getTranslationAccess($entity, 'delete', $account));
      }

      return $access;
    }

    // Block all other operations for deleted entities.
    return $access->andIf(AccessResult::forbidden(!$entity instanceof TranslatableInterface || $entity->isDefaultTranslation()
      ? 'Entity is deleted' :
      'Translation is deleted'
    ));
  }

  /**
   * Checks content-translation update/delete access for the given account.
   *
   * Copies ContentTranslationHandler::getTranslationAccess() but checks the
   * passed $account. Core's method checks the current user instead, which
   * returns the wrong answer (and caches it under the wrong account) when this
   * hook is asked about a non-current user.
   *
   * @todo Delegate back to
   *   ContentTranslationHandler::getTranslationAccess() once its own @todo
   *   (moving the logic into a translation access control handler that takes
   *   the account) is resolved.
   */
  private function getTranslationAccess(EntityInterface $entity, string $op, AccountInterface $account): AccessResultInterface {
    $entity_type = $entity->getEntityType();
    $translate_permission = TRUE;
    // Entity types without permission granularity need no translate permission.
    if (!$account->hasPermission('translate any entity') && $permission_granularity = $entity_type->getPermissionGranularity()) {
      $translate_permission = $account->hasPermission($permission_granularity === 'bundle'
        ? "translate {$entity->bundle()} {$entity->getEntityTypeId()}"
        : "translate {$entity->getEntityTypeId()}");
    }

    $access = AccessResult::allowedIf($translate_permission && $account->hasPermission("$op content translations"))->cachePerPermissions();
    if (!$access->isAllowed()) {
      // The fallback update check re-enters entity access. Mark the entity so
      // entityAccess() steps aside for exactly this check, and run it in the
      // 'ignore' context so storage behaves as if trash was disabled.
      $this->translationAccessEntity = $entity;
      try {
        $update_access = $this->trashManager->executeInTrashContext('ignore', fn () => $entity->access('update', $account, TRUE));
      }
      finally {
        $this->translationAccessEntity = NULL;
        // Access handlers cache results per entity, operation and account with
        // no trash dimension. The re-entrant check ran with the deleted-entity
        // forbid relaxed, so drop what was cached during the call to keep it
        // from being replayed for regular access checks.
        $this->entityTypeManager->getAccessControlHandler($entity->getEntityTypeId())->resetCache();
      }
      return AccessResult::allowedIfHasPermission($account, 'translate editable entities')
        ->andIf($update_access);
    }
    return $access;
  }

}
