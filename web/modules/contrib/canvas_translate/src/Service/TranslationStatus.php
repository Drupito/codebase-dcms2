<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Computes per-language translation status for Canvas content entities.
 *
 * Status is derived from three cheap sources, joined in memory:
 * - the entity's existing translations,
 * - core's content_translation_outdated flag (auto-set on source change by
 *   \Drupal\canvas_translate\Hook\OutdatedFlagHooks),
 * - this module's draft store (langcode-aware draft keys).
 */
final class TranslationStatus {

  public const string SOURCE = 'source';
  public const string NOT_TRANSLATED = 'not_translated';
  public const string DRAFT = 'draft';
  public const string UP_TO_DATE = 'up_to_date';
  public const string OUTDATED = 'outdated';

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly TranslationDraftStore $draftStore,
    private readonly ApprovalStore $approvalStore,
  ) {}

  /**
   * Returns the set of draft keys ("type:id:langcode") in one scan.
   *
   * @return array<string, true>
   *   Keyed by draft key for O(1) lookup; avoids a per-language store read.
   */
  public function loadDraftKeys(): array {
    return $this->draftStore->allKeys();
  }

  /**
   * Returns the set of approved draft keys in one scan.
   *
   * @return array<string, true>
   *   Keyed identically to ::loadDraftKeys(), for O(1) lookup.
   */
  public function loadApprovalKeys(): array {
    return $this->approvalStore->allKeys();
  }

  /**
   * Computes per-language status for a single entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The (default-translation) entity.
   * @param array<string, true> $draftKeys
   *   The result of ::loadDraftKeys(), passed in to avoid re-scanning per page.
   * @param array<string, true> $approvalKeys
   *   The result of ::loadApprovalKeys(), passed in to avoid re-scanning per
   *   page.
   *
   * @return array<string, array{status: string, hasDraft: bool, approved: bool, changed: ?int}>
   *   Keyed by langcode.
   */
  public function forEntity(ContentEntityInterface $entity, array $draftKeys, array $approvalKeys = []): array {
    $source_langcode = $entity->getUntranslated()->language()->getId();
    $result = [];
    foreach (\array_keys($this->languageManager->getLanguages()) as $langcode) {
      $key = $entity->getEntityTypeId() . ':' . $entity->id() . ':' . $langcode;
      $has_draft = isset($draftKeys[$key]);
      $changed = NULL;
      if ($langcode === $source_langcode) {
        $status = self::SOURCE;
        $changed = $this->changedTime($entity);
      }
      elseif (!$entity->hasTranslation($langcode)) {
        $status = $has_draft ? self::DRAFT : self::NOT_TRANSLATED;
      }
      else {
        $translation = $entity->getTranslation($langcode);
        $changed = $this->changedTime($translation);
        if ($has_draft) {
          $status = self::DRAFT;
        }
        elseif (self::isOutdated($translation)) {
          $status = self::OUTDATED;
        }
        else {
          $status = self::UP_TO_DATE;
        }
      }
      $result[$langcode] = [
        'status' => $status,
        'hasDraft' => $has_draft,
        'approved' => $has_draft && isset($approvalKeys[$key]),
        'changed' => $changed,
      ];
    }
    return $result;
  }

  /**
   * Whether a translation is flagged outdated by content_translation.
   */
  public static function isOutdated(ContentEntityInterface $translation): bool {
    return $translation->hasField('content_translation_outdated')
      && (bool) $translation->get('content_translation_outdated')->value;
  }

  /**
   * The entity's changed time, if it has one.
   */
  private function changedTime(ContentEntityInterface $entity): ?int {
    if (!$entity->hasField('changed') || $entity->get('changed')->isEmpty()) {
      return NULL;
    }
    return (int) $entity->get('changed')->value;
  }

}
