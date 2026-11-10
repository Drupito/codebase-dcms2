<?php

namespace Drupal\trash\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\trash\Trash;
use Symfony\Component\Routing\Route;

/**
 * Access check for entity translation CRUD operation.
 *
 * This blocks accessing translation update/delete routes to avoid errors when
 * submitting those forms. Needed as content_translation does not use entity
 * operation access checks for them.
 *
 * @see \Drupal\content_translation\Access\ContentTranslationManageAccessCheck
 */
class ContentTranslationManageAccessCheck implements AccessInterface {

  public function __construct(
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Checks translation access for the entity operations on the given route.
   *
   * Note: access checks for routes are AND:ed together, access is denied if any
   * results are neutral or forbidden.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param string $source
   *   (optional) For a create operation, the language code of the source.
   * @param string $target
   *   (optional) For a create operation, the language code of the translation.
   * @param string $language
   *   (optional) For an update or delete operation, the language code of the
   *   translation being updated or deleted.
   * @param string $entity_type_id
   *   (optional) The entity type ID.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account, $source = NULL, $target = NULL, $language = NULL, $entity_type_id = NULL) {
    $entity = $route_match->getParameter($entity_type_id);
    if (!$entity instanceof ContentEntityInterface) {
      return AccessResult::neutral();
    }

    $operation = $route->getRequirement('_access_content_translation_manage');
    $language = ($language ? $this->languageManager->getLanguage($language) : NULL)
      ?: $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT);
    // Only block update/delete routes for deleted translations; submitting
    // those forms on a trashed translation would fail. Live translations stay
    // with content_translation's own access check, which runs for the same
    // requirement, so anything else has to be allowed here.
    if (in_array($operation, ['update', 'delete'], TRUE)
      && $entity->hasTranslation($language->getId())
      && Trash::entityIsDeleted($entity->getTranslation($language->getId()))
    ) {
      return AccessResult::forbidden('The translation is deleted.')
        ->addCacheableDependency($entity)
        ->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
    }

    // The decision depends on the current content language and on which
    // translations the entity has, so vary on both.
    return AccessResult::allowed()
      ->addCacheableDependency($entity)
      ->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
  }

}
