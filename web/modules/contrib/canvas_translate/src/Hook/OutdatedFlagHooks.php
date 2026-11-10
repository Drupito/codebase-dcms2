<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\Service\TranslatableFieldGlue;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Auto-flags translations as outdated when the source content changes.
 *
 * Core's content_translation_outdated flag is normally only set via the manual
 * "Flag other translations as outdated" checkbox on the translation form. Since
 * Canvas content is edited in the Canvas editor (not that form), nothing would
 * ever set it. This hook sets it whenever the source (default) translation's
 * *translatable* content changes — so a translator can see at a glance which
 * translations have drifted from the source.
 *
 * Only translatable strings are compared: re-laying-out a page or editing a
 * non-translatable prop does not mark translations outdated.
 */
final class OutdatedFlagHooks {

  public function __construct(
    private readonly TranslatableFieldGlue $glue,
  ) {}

  /**
   * Implements hook_entity_presave().
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if (!$entity instanceof Page || $entity->isNew() || !$entity->isDefaultTranslation()) {
      return;
    }
    $original = $entity->getOriginal();
    if (!$original instanceof Page) {
      return;
    }
    // No translations means nothing to flag — skip the (non-trivial)
    // extraction.
    if ($entity->getTranslationLanguages(FALSE) === []) {
      return;
    }
    // Only the source (default) translation drives staleness.
    $original = $original->getUntranslated();
    if ($this->sourceHash($entity) === $this->sourceHash($original)) {
      return;
    }
    foreach (\array_keys($entity->getTranslationLanguages(FALSE)) as $langcode) {
      $translation = $entity->getTranslation($langcode);
      if ($translation->hasField('content_translation_outdated')) {
        $translation->set('content_translation_outdated', TRUE);
      }
    }
  }

  /**
   * Stable hash of a page's translatable source strings.
   *
   * Ponytail: runs the typed-config extractor on every source-page save. Fine
   * at page-save frequency; revisit if pages are saved in tight loops.
   */
  private function sourceHash(Page $page): string {
    // Skip non-translatable fields: they are excluded from translation (see
    // ApiTranslateController::translatabilitySetup()), so a change to one
    // must not flag translations outdated — there is nothing to re-translate.
    $data = [];
    foreach (['title', 'description', 'components'] as $field_name) {
      if (!$page->hasField($field_name) || !$page->getFieldDefinition($field_name)->isTranslatable()) {
        continue;
      }
      $data[$field_name] = $field_name === 'components'
        ? $this->glue->extractTranslatableData($page->get('components'))
        : $page->get($field_name)->value;
    }
    self::recursiveKsort($data);
    return \hash('xxh64', \json_encode($data, \JSON_THROW_ON_ERROR));
  }

  /**
   * Sorts an array by key, recursively.
   */
  private static function recursiveKsort(array &$array): void {
    \ksort($array);
    foreach ($array as &$value) {
      if (\is_array($value)) {
        self::recursiveKsort($value);
      }
    }
  }

}
