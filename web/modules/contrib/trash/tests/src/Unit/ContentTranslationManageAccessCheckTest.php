<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\trash\Access\ContentTranslationManageAccessCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;

/**
 * Tests the ContentTranslationManageAccessCheck service.
 */
#[CoversClass(ContentTranslationManageAccessCheck::class)]
#[Group('trash')]
class ContentTranslationManageAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The access check adds cache contexts, which validates them against the
    // cache_contexts_manager service.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Test the access method.
   *
   * The route is set up to expect operations on nodes. The test can pass in
   * different entity types, languages and deleted states to test the outcome.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $expected
   *   The expected access outcome. Only the type of the result is verified.
   * @param string $entityTypeId
   *   The entity type sent to the route.
   * @param string $operation
   *   The operation being required by the route.
   * @param string|null $langcode
   *   The language code being sent to the route.
   * @param bool $deleted
   *   Whether the checked translation is in the trash.
   */
  #[DataProvider('providerAccessTest')]
  public function testAccess(
    AccessResultInterface $expected,
    string $entityTypeId,
    string $operation,
    ?string $langcode = NULL,
    bool $deleted = FALSE,
  ): void {
    $language_manager = $this->createMock(LanguageManagerInterface::class);

    if ($langcode !== NULL) {
      $language_manager->expects($this->once())
        ->method('getLanguage')
        ->willReturn(new Language(['id' => $langcode]));
    }
    $language_manager->expects($this->any())
      ->method('getCurrentLanguage')
      ->willReturn(new Language(['id' => 'en']));

    $checker = new ContentTranslationManageAccessCheck($language_manager);
    $account = new AnonymousUserSession();
    $relevant_operation = $entityTypeId === 'node' && in_array($operation, ['update', 'delete'], TRUE);
    $translatable = in_array($langcode, ['sv', 'en'], TRUE);

    $node = $this->createMock(NodeInterface::class);
    // The access check adds the entity as a cacheable dependency, so the mock
    // must behave like a real cacheable object.
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheTags')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $node->expects($relevant_operation ? $this->once() : $this->never())
      ->method('hasTranslation')
      ->willReturn($translatable);
    $node->expects($relevant_operation && $translatable ? $this->once() : $this->never())
      ->method('getTranslation')
      ->willReturn($node);
    // The check must not delegate to entity access; it only inspects the
    // translation's deleted state.
    $node->expects($this->never())
      ->method('access');

    // Mock the field structure Trash::entityIsDeleted() inspects.
    $storage_definition = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage_definition->method('getProvider')->willReturn('trash');
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getFieldStorageDefinition')->willReturn($storage_definition);
    $deleted_field = $this->createMock(FieldItemListInterface::class);
    $deleted_field->method('isEmpty')->willReturn(!$deleted);
    $node->method('getFieldDefinition')->with('deleted')->willReturn($field_definition);
    $node->method('get')->with('deleted')->willReturn($deleted_field);

    $route = new Route('/', [
      'node' => NULL,
    ], [
      '_access_content_translation_manage' => $operation,
    ]);
    $route_match = new RouteMatch('test', $route, ['node' => $node], ['node' => 1]);

    $actual = $checker->access(
      $route,
      $route_match,
      $account,
      NULL,
      NULL,
      $langcode,
      $entityTypeId
    );

    $this->assertInstanceOf(\get_class($expected), $actual);
    $this->assertSame($expected->isAllowed(), $actual->isAllowed());
    $this->assertSame($expected->isForbidden(), $actual->isForbidden());
  }

  /**
   * Provider for testAccess().
   */
  public static function providerAccessTest(): iterable {
    yield 'Not a content entity type' => [
      'expected' => AccessResult::neutral(),
      'entityTypeId' => 'unknown',
      'operation' => 'update',
      'langcode' => NULL,
    ];

    yield 'View node without language set' => [
      'expected' => AccessResult::allowed(),
      'entityTypeId' => 'node',
      'operation' => 'view',
      'langcode' => NULL,
    ];

    yield 'Update node without language set' => [
      'expected' => AccessResult::allowed(),
      'entityTypeId' => 'node',
      'operation' => 'update',
      'langcode' => NULL,
    ];

    yield 'Delete node without language set' => [
      'expected' => AccessResult::allowed(),
      'entityTypeId' => 'node',
      'operation' => 'delete',
      'langcode' => NULL,
    ];

    yield 'Update live node translation' => [
      'expected' => AccessResult::allowed(),
      'entityTypeId' => 'node',
      'operation' => 'update',
      'langcode' => 'sv',
    ];

    yield 'Delete live node translation' => [
      'expected' => AccessResult::allowed(),
      'entityTypeId' => 'node',
      'operation' => 'delete',
      'langcode' => 'sv',
    ];

    yield 'Update deleted node translation' => [
      'expected' => AccessResult::forbidden(),
      'entityTypeId' => 'node',
      'operation' => 'update',
      'langcode' => 'sv',
      'deleted' => TRUE,
    ];

    yield 'Delete deleted node translation' => [
      'expected' => AccessResult::forbidden(),
      'entityTypeId' => 'node',
      'operation' => 'delete',
      'langcode' => 'sv',
      'deleted' => TRUE,
    ];

    yield 'Missing node translation' => [
      'expected' => AccessResult::allowed(),
      'entityTypeId' => 'node',
      'operation' => 'update',
      'langcode' => 'de',
    ];

    yield 'Unknown operation on translation' => [
      'expected' => AccessResult::allowed(),
      'entityTypeId' => 'node',
      'operation' => 'hug',
      'langcode' => 'sv',
    ];
  }

}
