<?php

declare(strict_types=1);

namespace Drupal\trash\Plugin\Menu;

use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TypedData\TranslatableInterface;

/**
 * Local task for a trash operation on the entity being viewed.
 *
 * @see \Drupal\trash\Hook\TrashEntityHooks::entityOperationAlter()
 */
class TrashOperationLocalTask extends LocalTaskDefault {

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match) {
    $parameters = parent::getRouteParameters($route_match);

    // The current route provides a language of its own, keep it.
    if (array_key_exists('language', $parameters)) {
      return $parameters;
    }

    // The {language} slug is only present on multilingual sites. Passing a
    // parameter the route does not have would turn it into a query string.
    $route = $this->routeProvider()->getRouteByName($this->getRouteName());
    if (!in_array('language', $route->compile()->getVariables(), TRUE)) {
      return $parameters;
    }

    // Use the translation being viewed, so the scope of the operation does not
    // depend on the negotiated content language.
    $entity = $route_match->getParameter($this->pluginDefinition['trash_entity_type_id']);
    if ($entity instanceof TranslatableInterface) {
      $parameters['language'] = $entity->language()->getId();
    }

    return $parameters;
  }

}
