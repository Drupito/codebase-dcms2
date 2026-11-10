<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Service;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Stores in-progress translation drafts in storage owned by this module.
 *
 * A draft is the translator's per-language text: a flat map of dotted row key
 * (e.g. `title`, `components.0.text`, `component_tree.<uuid>.label`) to target
 * string, exactly as the side-by-side editor holds it. Drafts are keyed
 * "{subject}:{langcode}", where the subject is "{entity_type}:{id}" for content
 * pages and the config dependency name for config layouts.
 *
 * UPSTREAM(canvas): Translation drafts deliberately do NOT live in Canvas's
 * auto-save store (`canvas.auto_save`). Canvas's publish workflow
 * (\Drupal\canvas\Controller\ApiAutoSaveController::post(), via
 * includeSiblingTranslationAutoSaves() for content and
 * groupConfigEntityAutoSaves() for config) publishes every pending auto-save of
 * an entity — which would publish a translator's in-progress work the moment
 * someone publishes the default-language content. There is no per-entry
 * opt-out.
 *   Minimal Canvas change: let the auto-save/publish flow exclude or namespace
 *   module-owned drafts (e.g. non-default-translation entries, or entries
 *   tagged by the owning workspace) so they are not swept into "publish all".
 *   Then: delete this store and return to AutoSaveManager (which already
 *   provides langcode-keyed draft storage, draft-aware reads and preview
 *   application) at the call sites in ApiTranslateController,
 *   PreviewController, ConfigTranslatableGlue, TranslationStatus and
 *   DraftCleanupHooks.
 */
final class TranslationDraftStore {

  public function __construct(
    private readonly KeyValueStoreInterface $store,
  ) {}

  /**
   * Builds the storage key for a draft.
   */
  public static function key(string $subject, string $langcode): string {
    return $subject . ':' . $langcode;
  }

  /**
   * The subject of a content-entity translation draft ("{entity_type}:{id}").
   */
  public static function contentSubject(string $entity_type_id, string|int $id): string {
    return $entity_type_id . ':' . $id;
  }

  /**
   * Returns the stored values map for a draft, or NULL when none exists.
   *
   * @return array<string, string>|null
   *   The draft's values map, or NULL when no draft exists.
   */
  public function get(string $key): ?array {
    $value = $this->store->get($key);
    return \is_array($value) ? $value : NULL;
  }

  /**
   * Whether a draft exists for the key (including a deliberately empty one).
   */
  public function has(string $key): bool {
    return $this->store->has($key);
  }

  /**
   * Stores a draft's values map.
   *
   * @param string $key
   *   The draft key.
   * @param array<string, string> $values
   *   The draft's dotted-key => target-text values map.
   */
  public function set(string $key, array $values): void {
    $this->store->set($key, $values);
  }

  /**
   * Deletes a draft.
   */
  public function delete(string $key): void {
    $this->store->delete($key);
  }

  /**
   * Every draft key currently stored, for O(1) lookup and prefix cleanup.
   *
   * @return array<string, true>
   *   Keyed by draft key.
   */
  public function allKeys(): array {
    return \array_fill_keys(\array_keys($this->store->getAll()), TRUE);
  }

}
