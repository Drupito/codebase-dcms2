<?php

declare(strict_types=1);

namespace Drupal\canvas_translate_ai;

/**
 * Thrown when no Drupal AI provider is configured for text translation.
 *
 * The controller turns this into a 422 with a human-readable message, so a
 * misconfigured site gets clear guidance instead of a 500.
 */
final class AiTranslatorUnavailableException extends \RuntimeException {}
