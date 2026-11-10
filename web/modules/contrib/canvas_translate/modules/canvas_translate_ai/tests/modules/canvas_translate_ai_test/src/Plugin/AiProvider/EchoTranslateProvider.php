<?php

declare(strict_types=1);

namespace Drupal\canvas_translate_ai_test\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\OperationType\TranslateText\TranslateTextInput;
use Drupal\ai\OperationType\TranslateText\TranslateTextInterface;
use Drupal\ai\OperationType\TranslateText\TranslateTextOutput;
use Drupal\ai_test\Plugin\AiProvider\EchoProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Fake translate_text provider: echoes "{targetLanguage}:{text}".
 *
 * Extends the ai_test EchoProvider (which already implements every base method)
 * and adds only the translate_text operation, so tests get deterministic,
 * provider-free translations.
 */
#[AiProvider(
  id: 'echo_translate',
  label: new TranslatableMarkup('Echo Translate'),
)]
class EchoTranslateProvider extends EchoProvider implements TranslateTextInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [...parent::getSupportedOperationTypes(), 'translate_text'];
  }

  /**
   * {@inheritdoc}
   */
  public function translateText(TranslateTextInput $input, string $model_id, array $options = []): TranslateTextOutput {
    return new TranslateTextOutput($input->getTargetLanguage() . ':' . $input->getText(), NULL, NULL);
  }

}
