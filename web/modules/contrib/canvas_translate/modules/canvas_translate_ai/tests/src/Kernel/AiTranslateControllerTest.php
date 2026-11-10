<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate_ai\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate_ai\Controller\AiTranslateController;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the AI-translate endpoints' request guardrails and access checks.
 *
 * The AiTranslator service has its own coverage (AiTranslatorTest); this pins
 * the controller's HTTP contract: language/access validation, the cost/DoS caps
 * (item count + source length), the "AI not configured" path, and config-entity
 * routing — none of which need a working AI provider.
 *
 * @see \Drupal\canvas_translate_ai\AiTranslator
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(AiTranslateController::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class AiTranslateControllerTest extends CanvasKernelTestBase {

  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID_A = '11111111-1111-4111-8111-111111111111';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'language',
    'content_translation',
    'canvas_translate',
    'key',
    'ai',
    'canvas_translate_ai',
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
    $this->setUpCurrentUser(permissions: [
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      'translate any entity',
      'translate canvas content',
    ]);
  }

  /**
   * Tests that an unknown target language is rejected.
   */
  public function testRejectsUnknownLanguage(): void {
    $controller = AiTranslateController::create($this->container);
    $response = $controller->translate($this->createPage(), 'xx', self::itemsRequest([['key' => 't', 'source' => 'Hi']]));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Tests that translating into the page's own language is rejected.
   */
  public function testRejectsSourceLanguage(): void {
    $controller = AiTranslateController::create($this->container);
    $response = $controller->translate($this->createPage(), 'en', self::itemsRequest([['key' => 't', 'source' => 'Hi']]));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Tests that users who cannot edit the page are denied.
   */
  public function testForbiddenWithoutUpdateAccess(): void {
    $page = $this->createPage();
    // Switch to an authenticated user that cannot edit the page.
    $this->setUpCurrentUser(permissions: ['translate canvas content']);
    $controller = AiTranslateController::create($this->container);
    $response = $controller->translate($page, 'fr', self::itemsRequest([['key' => 't', 'source' => 'Hi']]));
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Tests that a request with too many items is rejected.
   */
  public function testRejectsTooManyItems(): void {
    $controller = AiTranslateController::create($this->container);
    $items = \array_fill(0, 201, ['key' => 'k', 'source' => 's']);
    $response = $controller->translate($this->createPage(), 'fr', self::itemsRequest($items));
    self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
  }

  /**
   * Tests that a source text over the length limit is rejected.
   */
  public function testRejectsTooLongSource(): void {
    $controller = AiTranslateController::create($this->container);
    $items = [['key' => 'k', 'source' => \str_repeat('a', 20001)]];
    $response = $controller->translate($this->createPage(), 'fr', self::itemsRequest($items));
    self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
  }

  /**
   * Tests the response when no AI provider is configured.
   */
  public function testReturns422WhenNoProviderConfigured(): void {
    // A valid request that passes every guardrail still fails gracefully (not a
    // 500) when no AI provider is set up.
    $controller = AiTranslateController::create($this->container);
    $response = $controller->translate($this->createPage(), 'fr', self::itemsRequest([['key' => 't', 'source' => 'Hi']]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
  }

  /**
   * Tests that an unknown config type is rejected.
   */
  public function testConfigRejectsUnknownType(): void {
    $controller = AiTranslateController::create($this->container);
    $request = self::itemsRequest([['key' => 't', 'source' => 'Hi']]);
    $response = $controller->translateConfig('not_a_type', 'x', 'fr', $request);
    self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Tests that a missing config entity returns a 404.
   */
  public function testConfigNotFound(): void {
    $controller = AiTranslateController::create($this->container);
    $request = self::itemsRequest([['key' => 't', 'source' => 'Hi']]);
    $response = $controller->translateConfig('page_region', 'does_not_exist', 'fr', $request);
    self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Creates a page with one heading component.
   */
  private function createPage(): Page {
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->headingVersion(),
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    return $page;
  }

  /**
   * The active version of the test heading component.
   */
  private function headingVersion(): string {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('component')->load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    return $component->getActiveVersion();
  }

  /**
   * Builds a POST request carrying an `items` payload.
   *
   * @param list<array{key: string, source: string}> $items
   *   The editor rows to translate.
   */
  private static function itemsRequest(array $items): Request {
    $request = Request::create('/', 'POST', content: (string) \json_encode(['items' => $items]));
    $request->headers->set('Content-Type', 'application/json');
    return $request;
  }

}
