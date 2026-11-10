<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Unit;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Tests\Core\ParamConverter\EntityConverterTest;
use Drupal\Tests\UnitTestCase;
use Drupal\trash\ParamConverter\TrashEntityConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test the Trash entity converter.
 *
 * Borrows Core's test to verify it behaves exactly like its EntityConverter by
 * default, and adds in language parameter tests.
 */
#[CoversClass(TrashEntityConverter::class)]
#[Group('trash')]
class TrashEntityParamConverterTest extends EntityConverterTest {

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore phpunit.callParent
   */
  protected function setUp(): void {
    UnitTestCase::setUp();
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityRepository = $this->createMock(EntityRepositoryInterface::class);

    $this->entityConverter = new TrashEntityConverter($this->entityTypeManager, $this->entityRepository);
  }

  /**
   * {@inheritdoc}
   */
  public static function providerTestApplies(): array {
    $data = parent::providerTestApplies();
    foreach ($data as &$case) {
      $case[2]->setOption('_trash_route', TRUE);
    }
    return $data;
  }

  /**
   * Tests the convert() method.
   */
  #[DataProvider('providerTestConvertWithLanguage')]
  public function testConvertWithLanguage($value, array $definition, array $defaults, $expected_result): void {
    $this->setUpMocks();

    $handler = function ($entity_type_id, $entity_id, $contexts = NULL) {
      $valid = $entity_type_id === 'entity_test' && $entity_id === 'valid_id';
      if (!$valid) {
        return NULL;
      }
      $entity = ['id' => 'valid_id'];
      if (isset($contexts['langcode'])) {
        $entity['translation'] = $contexts['langcode'];
      }
      return (object) $entity;
    };

    $this->entityRepository->expects(empty($definition['load_latest_revision']) ? $this->any() : $this->never())
      ->method('getCanonical')
      ->willReturnCallback($handler);

    $this->entityRepository->expects(empty($definition['load_latest_revision']) ? $this->never() : $this->any())
      ->method('getActive')
      ->willReturnCallback($handler);

    $this->assertEquals($expected_result, $this->entityConverter->convert($value, $definition, 'foo', $defaults));
  }

  /**
   * Provides test data for testConvertWithLanguage.
   */
  public static function providerTestConvertWithLanguage() {
    $data = [];

    $data['Existing translated entity type with language set'] = [
      'valid_id',
      ['type' => 'entity:entity_test'],
      [
        'foo' => 'valid_id',
        'language' => new Language(['id' => 'tst', 'name' => 'Test']),
      ],
      (object) ['id' => 'valid_id', 'translation' => 'tst'],
    ];

    $data['Invalid language type'] = [
      'valid_id',
      ['type' => 'entity:entity_test'],
      ['foo' => 'valid_id', 'language' => '123'],
      (object) ['id' => 'valid_id'],
    ];

    $data['Existing translated entity type with language set, latest revision'] = [
      'valid_id',
      ['load_latest_revision' => TRUE, 'type' => 'entity:entity_test'],
      [
        'foo' => 'valid_id',
        'language' => new Language(['id' => 'tst', 'name' => 'Test']),
      ],
      (object) ['id' => 'valid_id', 'translation' => 'tst'],
    ];

    $data['Invalid language type, latest revision'] = [
      'valid_id',
      ['load_latest_revision' => TRUE, 'type' => 'entity:entity_test'],
      ['foo' => 'valid_id', 'language' => '123'],
      (object) ['id' => 'valid_id'],
    ];

    return $data;
  }

  /**
   * Tests the convert() method with an invalid dynamic entity type.
   */
  public function testConvertWithInvalidDynamicEntityTypeAndLanguage(): void {
    $this->expectException(ParamNotConvertedException::class);
    $this->expectExceptionMessage('The "foo" parameter was not converted because the "invalid_id" parameter is missing.');
    $this->entityConverter->convert('id',
      [
        'type' => 'entity:{invalid_id}',
        'language' => new Language(['id' => 'tst', 'name' => 'Test']),
      ],
      'foo',
      ['foo' => 'id']
    );
  }

}
