<?php

declare(strict_types=1);

namespace Drupal\canvas_translate;

use Drupal\Core\Url;

/**
 * Deep links into the Canvas Translate extension.
 *
 * Canvas forwards the path after `/canvas/app/{extension_id}` to the extension
 * iframe as its hash route, so these land on the SPA's own routes.
 *
 * @see \Drupal\canvas\PathProcessor\CanvasPathProcessor
 * @see extension/App.tsx
 */
final class ExtensionUrl {

  /**
   * A page's overview, or its editor for one language.
   */
  public static function forPage(int|string $id, ?string $langcode = NULL): Url {
    $path = 'canvas/app/canvas_translate/page/' . $id;
    if ($langcode !== NULL) {
      $path .= '/' . $langcode;
    }
    return Url::fromUri('base:' . $path);
  }

}
