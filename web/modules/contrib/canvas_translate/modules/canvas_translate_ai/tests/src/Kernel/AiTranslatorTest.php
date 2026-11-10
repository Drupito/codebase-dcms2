<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate_ai\Kernel;

use Drupal\canvas_translate_ai\AiTranslator;
use Drupal\canvas_translate_ai\AiTranslatorUnavailableException;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the AI translation service against a fake translate_text provider.
 *
 * @see \Drupal\canvas_translate_ai\AiTranslator
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(AiTranslator::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class AiTranslatorTest extends CanvasKernelTestBase {

  /**
   * The fake translate_text op type isn't in ai.settings' static schema.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = ['ai.settings'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'language',
    'content_translation',
    'canvas_translate',
    'key',
    'ai',
    'ai_test',
    'canvas_translate_ai',
    'canvas_translate_ai_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The echoai chat provider looks up mocked responses in this entity.
    $this->installEntitySchema('ai_mock_provider_result');
  }

  /**
   * The AI translator service under test.
   */
  private function translator(): AiTranslator {
    return $this->container->get(AiTranslator::class);
  }

  /**
   * Translates non-empty sources, skips empties, and keys by the item key.
   */
  public function testTranslateSkipsEmptyAndKeysByKey(): void {
    $this->config('ai.settings')
      ->set('default_providers.translate_text', ['provider_id' => 'echo_translate', 'model_id' => 'gpt-test'])
      ->save();

    self::assertTrue($this->translator()->isAvailable());

    $result = $this->translator()->translate(
      [
        ['key' => 'title', 'format' => NULL, 'source' => 'Hello'],
        ['key' => 'empty', 'format' => NULL, 'source' => ''],
        ['key' => 'components.0.text', 'format' => 'rich', 'source' => '<p>Hi</p>'],
      ],
      'en',
      'fr',
    );

    // Empty source dropped; others translated and keyed by their key.
    self::assertSame(['title', 'components.0.text'], \array_keys($result));
    self::assertSame('fr:Hello', $result['title']);
    self::assertSame('fr:<p>Hi</p>', $result['components.0.text']);
  }

  /**
   * Falls back to the chat operation when no translate_text provider is set.
   */
  public function testFallsBackToChat(): void {
    // Only a chat provider (echoai does chat, not translate_text).
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-test'])
      ->save();

    self::assertTrue($this->translator()->isAvailable());

    $result = $this->translator()->translate(
      [
        ['key' => 'title', 'format' => NULL, 'source' => 'Hello'],
        ['key' => 'empty', 'format' => NULL, 'source' => ''],
      ],
      'en',
      'fr',
    );

    // Empty source still skipped; the chat provider's echo marker proves the
    // chat path ran for the non-empty row.
    self::assertSame(['title'], \array_keys($result));
    self::assertStringContainsString('Hello world! Input:', $result['title']);
  }

  /**
   * Throws (→ 422 in the controller) when no provider is configured.
   */
  public function testThrowsWhenNoProviderConfigured(): void {
    self::assertFalse($this->translator()->isAvailable());
    $this->expectException(AiTranslatorUnavailableException::class);
    $this->translator()->translate(
      [['key' => 'title', 'format' => NULL, 'source' => 'Hello']],
      'en',
      'fr',
    );
  }

}
