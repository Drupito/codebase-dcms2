<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Controller;

use Drupal\Core\Asset\AssetCollectionRendererInterface;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\editor\Plugin\EditorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves Drupal core's text editor assets to the translation extension.
 *
 * The extension is a static page in an iframe, not a Drupal-rendered page, so
 * nothing attaches the libraries a text format's editor needs. This returns
 * exactly what core would attach to a form using those formats — the editor
 * settings and the ordered JS/CSS — so the extension edits rich text in the
 * same CKEditor 5, with the same plugins and allowed markup, as Canvas.
 *
 * Libraries the extension already loaded are left out through core's standard
 * `ajax_page_state[libraries]` parameter, which the AjaxPageState middleware
 * decompresses; the returned `ajaxPageState` settings carry the updated set.
 *
 * @see \Drupal\editor\Plugin\EditorManager::getAttachments()
 * @see \Drupal\Core\StackMiddleware\AjaxPageState
 */
final class EditorAssetsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AssetResolverInterface $assetResolver,
    private readonly AssetCollectionRendererInterface $jsCollectionRenderer,
    private readonly AssetCollectionRendererInterface $cssCollectionRenderer,
    private readonly EditorManager $editorManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('config.factory'),
      $container->get('asset.resolver'),
      $container->get('asset.js.collection_renderer'),
      $container->get('asset.css.collection_renderer'),
      $container->get('plugin.manager.editor'),
    );
  }

  /**
   * Returns the editor assets for the requested text formats.
   *
   * Query: `formats` (comma-separated text format machine names) and,
   * optionally, core's `ajax_page_state[libraries]`.
   *
   * Response: `settings` (drupalSettings to merge), `js` and `css` (the
   * attributes of each script/stylesheet element, in load order), and
   * `unavailable` (requested formats with no CKEditor 5 editor, or that the
   * current user may not use).
   */
  public function get(Request $request): JsonResponse {
    $requested = \array_values(\array_unique(\array_filter(\explode(',', (string) $request->query->get('formats', '')))));
    $available = $this->ckeditor5Formats($requested);
    $unavailable = \array_values(\array_diff($requested, $available));
    if ($available === []) {
      return new JsonResponse(['settings' => [], 'js' => [], 'css' => [], 'unavailable' => $unavailable]);
    }

    $assets = AttachedAssets::createFromRenderArray(['#attached' => $this->editorManager->getAttachments($available)]);
    // Read the query, which the AjaxPageState middleware decompresses on every
    // supported core version. Core only copies it to a request attribute since
    // 11.4.
    $page_state = $request->query->all('ajax_page_state');
    if (!empty($page_state['libraries'])) {
      $assets->setAlreadyLoadedLibraries(\explode(',', $page_state['libraries']));
    }

    $language = $this->languageManager->getCurrentLanguage();
    $performance = $this->configFactory->get('system.performance');
    $css = $this->assetResolver->getCssAssets($assets, (bool) $performance->get('css.preprocess'), $language);
    [$js_header, $js_footer] = $this->assetResolver->getJsAssets($assets, (bool) $performance->get('js.preprocess'), $language);
    $js = \array_merge($js_header, $js_footer);
    // Settings are handed over as data, not as an inline script element.
    $settings = $js['drupalSettings']['data'] ?? [];
    unset($js['drupalSettings']);

    return new JsonResponse([
      'settings' => $settings,
      'js' => self::elementAttributes($this->jsCollectionRenderer->render($js)),
      'css' => self::elementAttributes($this->cssCollectionRenderer->render($css)),
      'unavailable' => $unavailable,
    ]);
  }

  /**
   * Filters format machine names to those edited with CKEditor 5.
   *
   * @param list<string> $formats
   *   Requested text format machine names.
   *
   * @return list<string>
   *   The subset with an enabled format the current user may use and a
   *   CKEditor 5 editor.
   */
  private function ckeditor5Formats(array $formats): array {
    if ($formats === []) {
      return [];
    }
    $filter_formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple($formats);
    $editors = $this->entityTypeManager->getStorage('editor')->loadMultiple($formats);
    return \array_values(\array_filter(
      $formats,
      static fn (string $format): bool => isset($filter_formats[$format], $editors[$format])
        && $filter_formats[$format]->status()
        && $filter_formats[$format]->access('use')
        && $editors[$format]->getEditor() === 'ckeditor5',
    ));
  }

  /**
   * Extracts each rendered asset element's attributes, in order.
   *
   * @param array $elements
   *   Render arrays from an asset collection renderer.
   *
   * @return list<array<string, string|bool>>
   *   The `#attributes` of every element that loads a file (`src` or `href`).
   */
  private static function elementAttributes(array $elements): array {
    $attributes = [];
    foreach ($elements as $element) {
      $element_attributes = $element['#attributes'] ?? [];
      if (isset($element_attributes['src']) || isset($element_attributes['href'])) {
        $attributes[] = $element_attributes;
      }
    }
    return $attributes;
  }

}
