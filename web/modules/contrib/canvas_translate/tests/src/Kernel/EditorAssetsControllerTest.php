<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate\Kernel;

use Drupal\canvas_translate\Controller\EditorAssetsController;
use Drupal\Component\Utility\UrlHelper;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the endpoint serving core's text editor assets to the extension.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(EditorAssetsController::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class EditorAssetsControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'language',
    'content_translation',
    'canvas_translate',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    // Unaggregated, so the response names the individual library files.
    $this->config('system.performance')
      ->set('css.preprocess', FALSE)
      ->set('js.preprocess', FALSE)
      ->save();
    $this->setUpCurrentUser(permissions: ['translate canvas content']);
  }

  /**
   * A CKEditor 5 format gets core's settings and its scripts, in order.
   */
  public function testReturnsCoreEditorAssets(): void {
    $assets = $this->getAssets('canvas_html_block');

    self::assertSame([], $assets['unavailable']);
    $editor_settings = $assets['settings']['editor']['formats']['canvas_html_block']['editorSettings'];
    self::assertContains('link.Link', $editor_settings['plugins']);
    self::assertNotEmpty($editor_settings['toolbar']);
    // Core's settings loader, the editor bootstrap and the CKEditor 5 DLL are
    // all delivered, and the DLL comes before the editor that uses it.
    $scripts = self::sources($assets['js']);
    $loader = self::position($scripts, 'core/misc/drupalSettingsLoader.js');
    $dll = self::position($scripts, 'ckeditor5-dll/ckeditor5-dll.js');
    $bootstrap = self::position($scripts, 'core/modules/ckeditor5/js/ckeditor5.js');
    self::assertLessThan($dll, $loader);
    self::assertLessThan($bootstrap, $dll);
    self::assertNotEmpty($assets['css']);
    self::assertArrayHasKey('libraries', $assets['settings']['ajaxPageState']);
  }

  /**
   * Formats without a CKEditor 5 editor are reported, not served.
   */
  public function testFormatsWithoutEditorAreUnavailable(): void {
    FilterFormat::create(['format' => 'no_editor', 'name' => 'No editor'])->save();

    $assets = $this->getAssets('no_editor,missing_format,canvas_html_block');

    self::assertSame(['no_editor', 'missing_format'], $assets['unavailable']);
    self::assertSame(['canvas_html_block'], \array_keys($assets['settings']['editor']['formats']));

    $none = $this->getAssets('no_editor');
    self::assertSame(['no_editor'], $none['unavailable']);
    self::assertSame([], $none['js']);
  }

  /**
   * Libraries the extension already loaded are not sent again.
   */
  public function testLeavesOutAlreadyLoadedLibraries(): void {
    $loaded = UrlHelper::compressQueryParameter('core/drupal,core/jquery,core/drupalSettings,core/once');
    $scripts = self::sources($this->getAssets('canvas_html_block', ['libraries' => $loaded])['js']);

    self::assertNotContains(TRUE, \array_map(static fn (string $src): bool => \str_contains($src, 'jquery.min.js') || \str_contains($src, 'core/misc/drupal.js'), $scripts));
    self::assertNotFalse(self::position($scripts, 'core/modules/ckeditor5/js/ckeditor5.js'));
  }

  /**
   * The endpoint requires the translate permission.
   */
  public function testRequiresTranslatePermission(): void {
    $this->setUpCurrentUser(permissions: []);
    $this->expectException(AccessDeniedHttpException::class);
    $this->request(Request::create('/canvas-translate/api/editor-assets?formats=canvas_html_block'));
  }

  /**
   * Requests the assets for a comma-separated list of formats.
   */
  private function getAssets(string $formats, array $ajax_page_state = []): array {
    $query = ['formats' => $formats];
    if ($ajax_page_state !== []) {
      $query['ajax_page_state'] = $ajax_page_state;
    }
    $response = $this->request(Request::create('/canvas-translate/api/editor-assets?' . \http_build_query($query)));
    self::assertSame(200, $response->getStatusCode());
    return \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);
  }

  /**
   * The `src` of each script element.
   *
   * @return list<string>
   *   Script URLs, in load order.
   */
  private static function sources(array $scripts): array {
    return \array_map(static fn (array $attributes): string => $attributes['src'], $scripts);
  }

  /**
   * The index of the first URL containing a path, failing if there is none.
   */
  private static function position(array $urls, string $path): int {
    foreach ($urls as $index => $url) {
      if (\str_contains($url, $path)) {
        return $index;
      }
    }
    self::fail("No asset URL contains $path.");
  }

}
