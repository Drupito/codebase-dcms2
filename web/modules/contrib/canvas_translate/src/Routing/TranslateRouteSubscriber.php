<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Sends core's "Translate" action to the Canvas Translate extension.
 *
 * Keeps the route, path and access checks; only swaps the controller so the
 * Translate local task on a canvas_page opens the extension instead of core's
 * translation overview table.
 */
final class TranslateRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.canvas_page.content_translation_overview');
    if ($route !== NULL) {
      $route->setDefault('_controller', '\Drupal\canvas_translate\Controller\TranslateRedirectController::overview');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    // Run after content_translation (-210), which (re)sets this controller.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -220];
    return $events;
  }

}
