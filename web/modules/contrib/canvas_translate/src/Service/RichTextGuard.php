<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Service;

use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\editor\Element as EditorElement;
use Drupal\filter\FilterFormatInterface;

/**
 * Applies core's text format rules to rich-text rows in the editor.
 *
 * Core's own forms let a user edit a value only in a text format they may
 * use, and pass the value through the editor's XSS filter before it reaches
 * the editor. The translation editor loads other people's content into that
 * same editor, so it follows the same two rules.
 *
 * @see \Drupal\filter\Element\TextFormat::processFormat()
 * @see \Drupal\editor\Element::filterXss()
 */
final class RichTextGuard {

  /**
   * Row values that carry HTML in the row's text format.
   */
  private const array HTML_KEYS = ['source', 'sourceWas', 'target', 'targetWas'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly EditorElement $editorElement,
  ) {}

  /**
   * Whether the current user may edit text in a format.
   */
  public function canUse(string $format_id): bool {
    $format = $this->load($format_id);
    return $format !== NULL && $format->access('use', $this->currentUser);
  }

  /**
   * Readies editor groups for the current user.
   *
   * Rich-text values are XSS-filtered as core filters them for its editor,
   * which also covers the source column, rendered as HTML. A row in a format
   * the user may not use is marked `readOnly`.
   *
   * @param list<array{rows: list<array<string, mixed>>}> $groups
   *   Editor groups, as built by the translation API.
   *
   * @return list<array{rows: list<array<string, mixed>>}>
   *   The same groups, filtered.
   */
  public function secureGroups(array $groups): array {
    foreach ($groups as &$group) {
      foreach ($group['rows'] as &$row) {
        if (!\is_string($row['format'] ?? NULL)) {
          continue;
        }
        $format = $this->load($row['format']);
        foreach (self::HTML_KEYS as $key) {
          if (\is_string($row[$key] ?? NULL) && $row[$key] !== '') {
            // FALSE means the format needs no filtering, as in Full HTML.
            $filtered = DeprecationHelper::backwardsCompatibleCall(
              \Drupal::VERSION,
              '11.4.0',
              fn (): string|false => $this->editorElement->filterXss($row[$key], $format),
              fn (): string|false => editor_filter_xss($row[$key], $format),
            );
            if (\is_string($filtered)) {
              $row[$key] = $filtered;
            }
          }
        }
        if ($format === NULL || !$format->access('use', $this->currentUser)) {
          $row['readOnly'] = TRUE;
        }
      }
    }
    return $groups;
  }

  /**
   * Keeps posted values for rows the user may not edit at their current value.
   *
   * @param array<string, mixed> $values
   *   Posted target values, keyed by row key.
   * @param array<string, ?string> $formats
   *   Each row's text format, keyed by row key; NULL for plain text.
   * @param array<string, string> $current
   *   Each row's current target value, keyed by row key.
   *
   * @return array<string, mixed>
   *   The values, with rows in a format the user may not use reset.
   */
  public function lockValues(array $values, array $formats, array $current): array {
    foreach ($formats as $key => $format) {
      if ($format === NULL || $this->canUse($format)) {
        continue;
      }
      if (($current[$key] ?? '') === '') {
        unset($values[$key]);
      }
      else {
        $values[$key] = $current[$key];
      }
    }
    return $values;
  }

  /**
   * Loads a text format.
   */
  private function load(string $format_id): ?FilterFormatInterface {
    $format = $this->entityTypeManager->getStorage('filter_format')->load($format_id);
    return $format instanceof FilterFormatInterface ? $format : NULL;
  }

}
