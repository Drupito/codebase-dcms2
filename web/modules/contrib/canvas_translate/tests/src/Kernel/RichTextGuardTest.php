<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\Controller\ApiTranslateController;
use Drupal\canvas_translate\Service\RichTextGuard;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that rich text follows core's text format access and XSS rules.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(RichTextGuard::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class RichTextGuardTest extends CanvasKernelTestBase {

  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const array PERMISSIONS = [
    Page::EDIT_PERMISSION,
    'translate any entity',
    'translate canvas content',
  ];

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
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    $this->generateComponentConfig();
  }

  /**
   * Rich text reaches the editor XSS-filtered, as in core's own forms.
   */
  public function testRichTextIsXssFiltered(): void {
    $this->setUpCurrentUser(permissions: [...self::PERMISSIONS, 'use text format canvas_html_block']);
    $page = $this->createPage('<p onclick="steal()">Hello <a href="javascript:steal()">there</a></p>');

    $row = $this->richRow($page);

    self::assertArrayNotHasKey('readOnly', $row);
    self::assertStringNotContainsString('onclick', $row['source']);
    self::assertStringNotContainsString('javascript:', $row['source']);
    self::assertStringContainsString('Hello', $row['source']);
  }

  /**
   * A field in a format the user may not use is read-only, here and on save.
   *
   * Canvas lets everyone use its own formats, so this uses another one.
   */
  public function testFormatWithoutAccessIsReadOnly(): void {
    FilterFormat::create(['format' => 'guarded', 'name' => 'Guarded'])->save();
    Editor::create(['format' => 'guarded', 'editor' => 'ckeditor5', 'image_upload' => ['status' => FALSE]])->save();
    $this->setUpCurrentUser(permissions: self::PERMISSIONS);
    $page = $this->createPage('<p>Hello</p>', 'guarded');
    $controller = ApiTranslateController::create($this->container);

    $row = $this->richRow($page);
    self::assertTrue($row['readOnly']);

    $values = [$row['key'] => '<p>Bonjour</p>', 'title' => 'Titre'];
    $request = Request::create('/', 'PATCH', content: (string) \json_encode(['values' => $values]));
    $controller->saveDraft($page, 'fr', $request);
    $doc = self::decode($controller->getTranslation($page, 'fr'));
    self::assertSame('Titre', self::rows($doc)['title']['target']);
    self::assertSame('', self::rows($doc)[$row['key']]['target']);

    // The editor-assets endpoint does not serve that format's editor either.
    $response = $this->request(Request::create('/canvas-translate/api/editor-assets?formats=guarded'));
    self::assertSame(['guarded'], \json_decode((string) $response->getContent(), TRUE)['unavailable']);
  }

  /**
   * Creates a page with one rich-text component.
   */
  private function createPage(string $html, string $format = 'canvas_html_block'): Page {
    if (JavaScriptComponent::load('guard_paragraph') === NULL) {
      JavaScriptComponent::create([
        'machineName' => 'guard_paragraph',
        'name' => 'Guard paragraph',
        'status' => TRUE,
        'props' => [
          'text' => [
            'type' => 'string',
            'title' => 'Text',
            'contentMediaType' => 'text/html',
            'x-formatting-context' => 'block',
            'examples' => ['<p>Example</p>'],
          ],
        ],
        'js' => ['original' => 'export default () => null;', 'compiled' => 'export default () => null;'],
        'css' => ['original' => '', 'compiled' => ''],
        'slots' => [],
        'dataDependencies' => [],
      ])->save();
      // The component's inputs schema is generated from its props.
      $this->container->get('config.typed')->clearCachedDefinitions();
    }
    $component = Component::load('js.guard_paragraph');
    \assert($component instanceof Component);
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => '11111111-1111-4111-8111-111111111111',
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['text' => ['value' => $html, 'format' => $format]],
        ],
      ],
    ]);
    $page->save();
    return $page;
  }

  /**
   * The page's one rich-text row in the French editor.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function richRow(Page $page): array {
    $doc = self::decode(ApiTranslateController::create($this->container)->getTranslation($page, 'fr'));
    $rich = \array_values(\array_filter(self::rows($doc), static fn (array $row): bool => $row['format'] !== NULL));
    self::assertCount(1, $rich);
    return $rich[0];
  }

  /**
   * Every editor row, keyed by row key.
   *
   * @param array<string, mixed> $doc
   *   A decoded translation document.
   *
   * @return array<string, array<string, mixed>>
   *   The rows.
   */
  private static function rows(array $doc): array {
    $rows = \array_merge(...\array_column($doc['groups'], 'rows'));
    return \array_column($rows, NULL, 'key');
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  private static function decode(JsonResponse $response): array {
    return \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);
  }

}
