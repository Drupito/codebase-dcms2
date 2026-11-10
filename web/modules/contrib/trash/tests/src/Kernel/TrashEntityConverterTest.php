<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Language\Language;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\entity_test\EntityTestHelper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\trash\ParamConverter\TrashEntityConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests the Trash entity param converter.
 *
 * Copies Core's test to verify it behaves exactly like its EntityConverter by
 * default, switches to a translatable entity and adds in language parameter
 * tests.
 */
#[CoversClass(TrashEntityConverter::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashEntityConverterTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'user',
    'trash',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setUpCurrentUser();
    $this->installEntitySchema('entity_test_mul');

    // Create some testing bundles for 'entity_test_mul' entity type.
    EntityTestHelper::createBundle('foo', 'Foo', 'entity_test_mul');
    EntityTestHelper::createBundle('bar', 'Bar', 'entity_test_mul');
    EntityTestHelper::createBundle('baz', 'Baz', 'entity_test_mul');

    ConfigurableLanguage::createFromLangcode('sv')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * Tests an entity route parameter having 'bundle' definition property.
   */
  public function testRouteParamWithBundleDefinition(): void {
    $converter = $this->container->get(TrashEntityConverter::class);

    // The converter only applies to routes flagged with the '_trash_route'
    // option, so it cannot hijack upcasting on regular entity routes.
    $route = new Route('/entity_test_mul/{entity_test_mul}');
    $applies_definition = ['type' => 'entity:entity_test_mul'];
    $this->assertFalse($converter->applies($applies_definition, 'entity_test_mul', $route));
    $route->setOption('_trash_route', TRUE);
    $this->assertTrue($converter->applies($applies_definition, 'entity_test_mul', $route));

    $entity1 = EntityTestMul::create([
      'name' => $this->randomString(),
      'type' => 'foo',
      'language' => 'en',
    ]);
    $entity1->addTranslation('sv', ['title' => $this->randomString()]);
    $entity1->save();
    $entity2 = EntityTestMul::create([
      'name' => $this->randomString(),
      'type' => 'bar',
      'language' => 'en',
    ]);
    $entity2->addTranslation('sv', ['title' => $this->randomString()]);
    $entity2->save();
    $entity3 = EntityTestMul::create([
      'name' => $this->randomString(),
      'type' => 'baz',
      'language' => 'en',
    ]);
    $entity3->addTranslation('sv', ['title' => $this->randomString()]);
    $entity3->save();

    $defaults_with_language = ['language' => new Language(['id' => 'sv'])];
    $definition = [
      'type' => 'entity:entity_test_mul',
      'bundle' => [
        'foo',
        'bar',
      ],
    ];

    // Run Core's tests to ensure it still works without a language.
    // An entity whose bundle is in the definition list is converted.
    $converted = $converter->convert($entity1->id(), $definition, 'qux', []);
    $this->assertSame($entity1->id(), $converted->id());
    $this->assertSame('en', $converted->language()->getId());

    // An entity whose bundle is in the definition list is converted.
    $converted = $converter->convert($entity2->id(), $definition, 'qux', []);
    $this->assertSame($entity2->id(), $converted->id());
    $this->assertSame('en', $converted->language()->getId());

    // An entity whose bundle is missed from definition is not converted.
    $converted = $converter->convert($entity3->id(), $definition, 'qux', []);
    $this->assertNull($converted);

    // A non-existing entity returns NULL.
    $converted = $converter->convert('some-non-existing-entity-id', $definition, 'qux', []);
    $this->assertNull($converted);

    // Repeat with an explicit language set.
    $converted = $converter->convert($entity1->id(), $definition, 'qux', $defaults_with_language);
    $this->assertSame($entity1->id(), $converted->id());
    $this->assertSame('sv', $converted->language()->getId());

    // An entity whose bundle is in the definition list is converted.
    $converted = $converter->convert($entity2->id(), $definition, 'qux', $defaults_with_language);
    $this->assertSame($entity2->id(), $converted->id());
    $this->assertSame('sv', $converted->language()->getId());

    // An entity whose bundle is missed from definition is not converted.
    $converted = $converter->convert($entity3->id(), $definition, 'qux', $defaults_with_language);
    $this->assertNull($converted);

    // A non-existing entity returns NULL.
    $converted = $converter->convert('some-non-existing-entity-id', $definition, 'qux', $defaults_with_language);
    $this->assertNull($converted);

    $definition = [
      'type' => 'entity:entity_test_mul',
    ];

    // Run Core's tests to ensure it still works without a language.
    // Check that all entities are returned when 'bundle' is not defined.
    $converted = $converter->convert($entity1->id(), $definition, 'qux', []);
    $this->assertSame($entity1->id(), $converted->id());
    $this->assertSame('en', $converted->language()->getId());
    $converted = $converter->convert($entity2->id(), $definition, 'qux', []);
    $this->assertSame($entity2->id(), $converted->id());
    $this->assertSame('en', $converted->language()->getId());
    $converted = $converter->convert($entity3->id(), $definition, 'qux', []);
    $this->assertSame($entity3->id(), $converted->id());
    $this->assertSame('en', $converted->language()->getId());
    $converted = $converter->convert('some-non-existing-entity-id', $definition, 'qux', []);
    $this->assertNull($converted);

    // Repeat with an explicit language set.
    $converted = $converter->convert($entity1->id(), $definition, 'qux', $defaults_with_language);
    $this->assertSame($entity1->id(), $converted->id());
    $this->assertSame('sv', $converted->language()->getId());
    $converted = $converter->convert($entity2->id(), $definition, 'qux', $defaults_with_language);
    $this->assertSame($entity2->id(), $converted->id());
    $this->assertSame('sv', $converted->language()->getId());
    $converted = $converter->convert($entity3->id(), $definition, 'qux', $defaults_with_language);
    $this->assertSame($entity3->id(), $converted->id());
    $this->assertSame('sv', $converted->language()->getId());
    $converted = $converter->convert('some-non-existing-entity-id', $definition, 'qux', $defaults_with_language);
    $this->assertNull($converted);
  }

}
