<?php

declare(strict_types=1);

namespace Drupal\canvas_translate_ai;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\TranslateText\TranslateTextInput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Machine-translates editor strings via Drupal AI.
 *
 * Prefers the dedicated `translate_text` operation; if no provider is
 * configured for it, falls back to the general `chat` operation with a
 * translation prompt (so sites with only a chat provider — e.g. OpenAI — still
 * get machine translation). Pure translation: it takes a list of
 * {key, format, source} items and returns a suggested target value per key. It
 * never loads or writes Canvas content — the caller applies the suggestions to
 * the existing translation draft.
 */
final class AiTranslator {

  public function __construct(
    private readonly AiProviderPluginManager $aiProvider,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Whether a translate_text or chat provider is configured.
   */
  public function isAvailable(): bool {
    return $this->hasProvider('translate_text') || $this->hasProvider('chat');
  }

  /**
   * Translates the source strings of the given items to the target language.
   *
   * @param list<array{key: string, format?: ?string, source: string}> $items
   *   Editor rows to translate. Items with an empty source are skipped.
   * @param string $sourceLangcode
   *   The source language code.
   * @param string $targetLangcode
   *   The target language code.
   *
   * @return array<string, string>
   *   Suggested target text, keyed by the item's key. Skipped items are absent.
   *
   * @throws \Drupal\canvas_translate_ai\AiTranslatorUnavailableException
   *   When neither a translate_text nor a chat provider is configured.
   */
  public function translate(array $items, string $sourceLangcode, string $targetLangcode): array {
    // Prefer the dedicated translate_text operation; fall back to chat.
    if (($tt = $this->getProvider('translate_text')) !== NULL) {
      [$provider, $model_id] = $tt;
      $translate = fn(string $source): string => $provider
        ->translateText(new TranslateTextInput($source, $sourceLangcode, $targetLangcode), $model_id)
        ->getNormalized();
    }
    elseif (($chat = $this->getProvider('chat')) !== NULL) {
      [$provider, $model_id] = $chat;
      $prompt = $this->chatSystemPrompt($sourceLangcode, $targetLangcode);
      $translate = fn(string $source): string => $this->chatTranslate($provider, $model_id, $prompt, $source);
    }
    else {
      throw new AiTranslatorUnavailableException('AI translation isn’t set up yet. Choose a default Translate Text or Chat provider in the Drupal AI settings to turn it on.');
    }

    // ponytail: sequential per-string loop, N provider calls per page. Fine for
    // the field counts a Canvas page has; chunk/parallelize if a page ever has
    // hundreds of translatable strings.
    $result = [];
    foreach ($items as $item) {
      $source = (string) ($item['source'] ?? '');
      $key = (string) ($item['key'] ?? '');
      if ($source === '' || $key === '') {
        continue;
      }
      // Rich-text rows (a non-null format) are sent verbatim so the provider
      // can preserve embedded markup; quality is provider-dependent.
      $result[$key] = $translate($source);
    }
    return $result;
  }

  /**
   * Translates one string through the chat operation.
   */
  private function chatTranslate(ProviderProxy $provider, string $model_id, string $systemPrompt, string $source): string {
    $output = $provider->chat(new ChatInput([
      new ChatMessage('system', $systemPrompt),
      new ChatMessage('user', $source),
    ]), $model_id);
    $normalized = $output->getNormalized();
    return $normalized instanceof ChatMessage ? $normalized->getText() : (string) $normalized;
  }

  /**
   * Builds the chat system prompt for a source → target language pair.
   */
  private function chatSystemPrompt(string $sourceLangcode, string $targetLangcode): string {
    $source = $this->languageName($sourceLangcode);
    $target = $this->languageName($targetLangcode);
    return \sprintf(
      'You are a professional translator. Translate the user message from %s to %s. Preserve all HTML markup, entities, and whitespace exactly. Output only the translated text, with no quotes, labels, or commentary.',
      $source,
      $target,
    );
  }

  /**
   * Returns the human-readable language name for a langcode, or the langcode.
   */
  private function languageName(string $langcode): string {
    return $this->languageManager->getLanguage($langcode)?->getName() ?? $langcode;
  }

  /**
   * Whether a usable default provider exists for an operation type.
   */
  private function hasProvider(string $operationType): bool {
    $default = $this->aiProvider->getDefaultProviderForOperationType($operationType);
    return \is_array($default) && !empty($default['provider_id']) && !empty($default['model_id']);
  }

  /**
   * Resolves the default provider for an operation type.
   *
   * @return array{0: \Drupal\ai\Plugin\ProviderProxy, 1: string}|null
   *   A [provider, model_id] tuple, or NULL when none is configured.
   */
  private function getProvider(string $operationType): ?array {
    $default = $this->aiProvider->getDefaultProviderForOperationType($operationType);
    if (!\is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
      return NULL;
    }
    // The configured default may point at a plugin that no longer exists
    // (module uninstalled, key removed). Treat that as "no provider" rather
    // than fatal.
    try {
      $instance = $this->aiProvider->createInstance((string) $default['provider_id']);
    }
    catch (\Exception) {
      return NULL;
    }
    return [$instance, (string) $default['model_id']];
  }

}
