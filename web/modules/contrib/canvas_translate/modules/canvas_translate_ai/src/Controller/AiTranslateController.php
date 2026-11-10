<?php

declare(strict_types=1);

namespace Drupal\canvas_translate_ai\Controller;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas_translate_ai\AiTranslator;
use Drupal\canvas_translate_ai\AiTranslatorUnavailableException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns AI-suggested target values for editor strings.
 *
 * These endpoints translate strings the editor already holds and hand back
 * suggestions; they never mutate stored content. The SPA applies the result to
 * the existing translation draft for the editor to review and publish.
 */
final class AiTranslateController implements ContainerInjectionInterface {

  /**
   * Config entity types whose component trees are translatable.
   *
   * Mirrors ApiTranslateController::CONFIG_TYPES.
   */
  private const array CONFIG_TYPES = [
    ContentTemplate::ENTITY_TYPE_ID,
    PageRegion::ENTITY_TYPE_ID,
    PageVariant::ENTITY_TYPE_ID,
  ];

  /**
   * Caps on a single AI-translate request (cost/DoS guardrails).
   *
   * Each item is one synchronous provider call, so an unbounded request burns
   * tokens and holds the connection open. A Canvas page has far fewer strings
   * than these limits in practice.
   */
  private const int MAX_ITEMS = 200;
  private const int MAX_SOURCE_LENGTH = 20000;

  public function __construct(
    private readonly AiTranslator $translator,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AiTranslator::class),
      $container->get('language_manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('canvas_translate_ai'),
    );
  }

  /**
   * Translates posted strings for one page + target language.
   */
  public function translate(ContentEntityInterface $canvas_page, string $langcode, Request $request): JsonResponse {
    if (($error = $this->validateTarget($langcode, $canvas_page->getUntranslated()->language()->getId())) !== NULL) {
      return $error;
    }
    if (!$canvas_page->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }
    return $this->run($request, $canvas_page->getUntranslated()->language()->getId(), $langcode);
  }

  /**
   * Translates posted strings for one config entity + target language.
   */
  public function translateConfig(string $type, string $id, string $langcode, Request $request): JsonResponse {
    if (!\in_array($type, self::CONFIG_TYPES, TRUE)) {
      return new JsonResponse(['error' => 'Unknown config type.'], Response::HTTP_NOT_FOUND);
    }
    $entity = $this->entityTypeManager->getStorage($type)->load($id);
    if (!$entity instanceof EntityInterface) {
      return new JsonResponse(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
    }
    if (!$entity->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this layout.'], Response::HTTP_FORBIDDEN);
    }
    $source_langcode = $entity->language()->getId();
    if (($error = $this->validateTarget($langcode, $source_langcode)) !== NULL) {
      return $error;
    }
    return $this->run($request, $source_langcode, $langcode);
  }

  /**
   * Reads items from the request and returns the translated values.
   */
  private function run(Request $request, string $sourceLangcode, string $targetLangcode): JsonResponse {
    $body = \json_decode($request->getContent(), TRUE);
    $items = \is_array($body['items'] ?? NULL) ? \array_values($body['items']) : [];
    if (\count($items) > self::MAX_ITEMS) {
      return new JsonResponse(['error' => 'Too many strings to translate at once.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }
    foreach ($items as $item) {
      if (\strlen((string) ($item['source'] ?? '')) > self::MAX_SOURCE_LENGTH) {
        return new JsonResponse(['error' => 'One of the strings is too long to translate.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
      }
    }
    try {
      $values = $this->translator->translate($items, $sourceLangcode, $targetLangcode);
    }
    catch (AiTranslatorUnavailableException $e) {
      return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
    catch (\Exception $e) {
      // A provider runtime failure (rate limit, quota, timeout, bad response)
      // should not surface as a 500 with a stack trace.
      $this->logger->error('AI translation failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'AI translation failed. Please try again in a moment.'], Response::HTTP_BAD_GATEWAY);
    }
    return new JsonResponse(['values' => $values]);
  }

  /**
   * Rejects unknown languages and the source language.
   */
  private function validateTarget(string $langcode, string $sourceLangcode): ?JsonResponse {
    if (!\array_key_exists($langcode, $this->languageManager->getLanguages())) {
      return new JsonResponse(['error' => 'Unknown language.'], Response::HTTP_BAD_REQUEST);
    }
    if ($langcode === $sourceLangcode) {
      return new JsonResponse(['error' => 'Cannot translate the source language.'], Response::HTTP_BAD_REQUEST);
    }
    return NULL;
  }

}
