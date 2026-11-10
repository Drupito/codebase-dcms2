<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Controller;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\ExtensionUrl;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects core's translation overview to the Canvas Translate extension.
 *
 * Lands on the page's own overview inside the extension, not the dashboard,
 * so the "Translate" operation and tab keep the page they were opened from.
 *
 * @see \Drupal\canvas_translate\Routing\TranslateRouteSubscriber
 */
final class TranslateRedirectController {

  /**
   * Redirects to the page's overview in the extension.
   */
  public function overview(Page $canvas_page): RedirectResponse {
    return new RedirectResponse(ExtensionUrl::forPage($canvas_page->id())->toString());
  }

}
