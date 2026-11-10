<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Service;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas_translate\Entity\ConfigBaseline;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;

/**
 * Extracts and writes translatable strings for Canvas CONFIG component trees.
 *
 * Config-entity translations are NOT entity translations: a *published* config
 * translation is stored as a language config override (e.g. the `fr` override
 * of `canvas.content_template.node.article.full`, in the `language.config.fr.*`
 * collection). This service is the config-path counterpart to
 * TranslatableFieldGlue: it enumerates the translatable strings of a
 * ContentTemplate or PageRegion's `component_tree`, reads existing target
 * strings, and writes posted target strings — leaving the source (active)
 * config untouched.
 *
 * The translatable strings live under each component instance's `inputs`
 * (delegated to the shared, tmgmt-free
 * \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor via
 * TranslatableFieldGlue) plus each instance's optional `label`. Keys are stable
 * and dotted, mirroring the storage shape of the override:
 *   component_tree.<uuid>.inputs.<prop…>
 *   component_tree.<uuid>.label
 * so the SPA can reuse FieldRow exactly as it does for content.
 *
 * Draft → publish layer: ::save() stages the translator's text in this module's
 * own draft store (\Drupal\canvas_translate\Service\TranslationDraftStore) —
 * NOT as a Canvas `StagedLanguageConfigOverride` and NOT in Canvas's auto-save
 * store — so Canvas's publish workflow never publishes a config translation as
 * a side effect of publishing the base layout. The live
 * `LanguageConfigOverride` is untouched until ::publish() writes it directly.
 * Reads overlay the staged draft (when one exists) onto the live override, so
 * the editor and dashboard reflect the pending draft.
 *
 * @see \Drupal\canvas_translate\Service\TranslatableFieldGlue
 * @see \Drupal\canvas_translate\Service\TranslationDraftStore
 * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
 */
final class ConfigTranslatableGlue {

  use StringTranslationTrait;

  /**
   * Source text placeholder the extractor emits for empty optional props.
   *
   * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
   */
  public const string EMPTY_PLACEHOLDER = '∅';

  public function __construct(
    private readonly TranslatableFieldGlue $glue,
    private readonly LanguageConfigFactoryOverrideInterface $languageConfigOverride,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TranslationDraftStore $draftStore,
  ) {}

  /**
   * Enumerates the translatable rows of a config entity's component tree.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeConfigEntityBase $entity
   *   A ContentTemplate or PageRegion.
   * @param string $langcode
   *   The target language; draft-or-override values are read for this language.
   *
   * @return list<array{component: array{uuid: string, label: string}, label: string, rows: list<array{key: string, label: string, format: ?string, source: string, target: string}>}>
   *   One group per component instance, each with side-by-side rows. The shape
   *   matches ApiTranslateController::getTranslation()'s `groups`.
   */
  public function groups(ComponentTreeConfigEntityBase $entity, string $langcode): array {
    $targets = $this->targetStringsByKey($entity, $langcode);
    // Per-field "what changed in the source since the override was last
    // published" — empty when in sync or when no baseline exists.
    $changed = $this->changedSourceStrings($entity, $langcode);
    // The published override, so the review screen can diff "what changes on
    // publish". Read only while a draft is pending — otherwise $targets already
    // is the published value and there is nothing to diff against.
    $has_draft = $this->draftStore->get($this->draftKey($entity, $langcode)) !== NULL;
    $published = $has_draft ? $this->liveOverrideStringsByKey($entity, $langcode) : [];
    $source_data = $this->glue->extractTranslatableData($entity->getComponentTree());

    $groups = [];
    foreach ($entity->getComponentTree() as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuid = $item->getUuid();
      $rows = [];

      // Optional instance label (sibling of `inputs` in the schema, so not
      // produced by the inputs extractor — add it explicitly).
      $source_label = (string) ($item->getLabel() ?? '');
      if ($source_label !== '') {
        $key = 'component_tree.' . $uuid . '.label';
        $row = [
          'key' => $key,
          'label' => (string) $this->t('Label'),
          'format' => NULL,
          'source' => $source_label,
          'target' => $targets[$key] ?? '',
        ];
        if (isset($changed[$key])) {
          $row['sourceWas'] = $changed[$key];
        }
        if ($has_draft) {
          $pub = $published[$key] ?? '';
          if ($pub !== ($row['target'] ?? '')) {
            $row['targetWas'] = $pub;
          }
        }
        $rows[] = $row;
      }

      // Translatable `inputs` leaves.
      foreach (self::flattenLeaves($source_data[$delta] ?? [], []) as $found) {
        ['path' => $path, 'leaf' => $leaf] = $found;
        $key = 'component_tree.' . $uuid . '.inputs.' . \implode('.', $path);
        $row = [
          'key' => $key,
          'label' => (string) ($leaf['#label'] ?? \end($path)),
          'format' => $leaf['#format'] ?? NULL,
          'source' => self::normalizeText($leaf['#text'] ?? ''),
          'target' => $targets[$key] ?? '',
        ];
        if (isset($changed[$key])) {
          $row['sourceWas'] = $changed[$key];
        }
        if ($has_draft) {
          $pub = $published[$key] ?? '';
          if ($pub !== ($row['target'] ?? '')) {
            $row['targetWas'] = $pub;
          }
        }
        $rows[] = $row;
      }

      if ($rows) {
        $component = $item->getComponent();
        $groups[] = [
          'component' => [
            'uuid' => $uuid,
            'label' => (string) ($item->getLabel() ?? $component?->label() ?? $item->getComponentId()),
          ],
          'label' => (string) ($component?->label() ?? $item->getComponentId()),
          'rows' => $rows,
        ];
      }
    }
    return $groups;
  }

  /**
   * Flattens every translatable source string to a key => text map.
   *
   * Keys match ::groups() and ::save(), so the completeness denominator is
   * exactly the set of rows shown in the editor.
   *
   * @return array<string, string>
   *   The source strings, keyed by their stable dotted key.
   */
  public function sourceStringsByKey(ComponentTreeConfigEntityBase $entity): array {
    $strings = [];
    $source_data = $this->glue->extractTranslatableData($entity->getComponentTree());
    foreach ($entity->getComponentTree() as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuid = $item->getUuid();
      $label = (string) ($item->getLabel() ?? '');
      if ($label !== '') {
        $strings['component_tree.' . $uuid . '.label'] = $label;
      }
      foreach (self::flattenLeaves($source_data[$delta] ?? [], []) as $found) {
        $key = 'component_tree.' . $uuid . '.inputs.' . \implode('.', $found['path']);
        $strings[$key] = self::normalizeText($found['leaf']['#text'] ?? '');
      }
    }
    return $strings;
  }

  /**
   * Reads the existing target strings — draft when present, else live override.
   *
   * @return array<string, string>
   *   Keyed by the same dotted keys as ::sourceStringsByKey().
   */
  public function targetStringsByKey(ComponentTreeConfigEntityBase $entity, string $langcode): array {
    $draft = $this->draftStore->get($this->draftKey($entity, $langcode));
    if ($draft !== NULL) {
      // The draft already holds the editor's per-row target text, keyed
      // identically.
      $strings = [];
      foreach ($draft as $key => $value) {
        if (\str_starts_with((string) $key, 'component_tree.')) {
          $strings[$key] = self::normalizeText($value);
        }
      }
      return $strings;
    }
    // No draft → flatten the live language override.
    return $this->liveOverrideStringsByKey($entity, $langcode);
  }

  /**
   * Flattens the live (published) language override to a key => text map.
   *
   * Unlike ::targetStringsByKey() this always reads the published override,
   * ignoring any pending draft — the baseline the review screen diffs against.
   *
   * @return array<string, string>
   *   Keyed by the same dotted keys as ::sourceStringsByKey().
   */
  public function liveOverrideStringsByKey(ComponentTreeConfigEntityBase $entity, string $langcode): array {
    $tree = $this->liveOverrideData($entity, $langcode)['component_tree'] ?? [];
    $strings = [];
    foreach ($tree as $uuid => $node) {
      if (!\is_array($node)) {
        continue;
      }
      if (\array_key_exists('label', $node) && \is_scalar($node['label'])) {
        $strings['component_tree.' . $uuid . '.label'] = (string) $node['label'];
      }
      foreach (self::flattenScalars($node['inputs'] ?? [], []) as $path => $value) {
        $strings['component_tree.' . $uuid . '.inputs.' . $path] = self::normalizeText($value);
      }
    }
    return $strings;
  }

  /**
   * Stages posted target strings as a workspace-owned draft (not written live).
   *
   * Only non-empty translatable string keys are kept; the active (source)
   * config and the live language override are never touched — the draft lives
   * in this module's own store until ::publish(). When there is nothing to
   * stage and nothing published to clear, no draft is created.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeConfigEntityBase $entity
   *   The config entity being translated.
   * @param string $langcode
   *   The target language.
   * @param array<string, string> $values
   *   Dotted-key => target text, as produced by ::groups().
   */
  public function save(ComponentTreeConfigEntityBase $entity, string $langcode, array $values): void {
    $clean = [];
    foreach ($values as $key => $text) {
      if (!\str_starts_with((string) $key, 'component_tree.') || !\is_string($text)) {
        continue;
      }
      if (\trim($text) === '') {
        // Don't persist empty target strings.
        continue;
      }
      $clean[$key] = $text;
    }

    $draft_key = $this->draftKey($entity, $langcode);
    if ($clean === [] && !$this->hasOverride($entity, $langcode)) {
      // Nothing staged and nothing published to remove — drop any pending draft
      // rather than persist an empty one.
      $this->draftStore->delete($draft_key);
      return;
    }
    // An empty $clean with an existing override is a deliberate "clear all"
    // draft that ::publish() will use to delete the override.
    $this->draftStore->set($draft_key, $clean);
  }

  /**
   * Publishes the staged draft to the live language override.
   *
   * Writes the draft's non-empty values into the live `LanguageConfigOverride`
   * (or deletes it when the draft is empty), then clears the draft and records
   * the source-strings baseline so staleness is tracked from this point.
   * Canvas's publish workflow is never involved, so a config translation goes
   * live only here.
   */
  public function publish(ComponentTreeConfigEntityBase $entity, string $langcode): void {
    $draft_key = $this->draftKey($entity, $langcode);
    $values = $this->draftStore->get($draft_key) ?? [];

    // Build the override's component_tree from the draft's non-empty values.
    $tree = [];
    foreach ($values as $key => $text) {
      if (!\str_starts_with((string) $key, 'component_tree.')) {
        continue;
      }
      $text = (string) $text;
      if (\trim($text) === '') {
        continue;
      }
      // component_tree.<uuid>.(label | inputs.<prop…>)
      $parts = \explode('.', \substr((string) $key, \strlen('component_tree.')));
      $uuid = \array_shift($parts);
      if ($uuid === NULL || $parts === []) {
        continue;
      }
      NestedArray::setValue($tree, [$uuid, ...$parts], $text);
    }

    $override = $this->languageConfigOverride->getOverride($langcode, $entity->getConfigDependencyName());
    if ($tree === []) {
      // Remove our component_tree, preserving any other top-level keys a
      // different writer may own; delete the override entirely if it becomes
      // empty.
      if (!$override->isNew()) {
        $data = $override->getRawData();
        $data = \is_array($data) ? $data : [];
        unset($data['component_tree']);
        if ($data === []) {
          $override->delete();
        }
        else {
          $override->setData($data)->save();
        }
      }
    }
    else {
      $data = $override->isNew() ? [] : $override->getRawData();
      $data = \is_array($data) ? $data : [];
      $data['component_tree'] = $tree;
      $override->setData($data)->save();
    }

    $this->draftStore->delete($draft_key);
    $this->writeBaseline($entity, $langcode);
  }

  /**
   * Discards pending changes, or the translation when none are pending.
   *
   * A pending draft is dropped, reverting to the last published state. When
   * there is no draft, the published live override itself is removed — the only
   * sensible meaning of "discard" once everything is committed.
   */
  public function discard(ComponentTreeConfigEntityBase $entity, string $langcode): void {
    $draft_key = $this->draftKey($entity, $langcode);
    if ($this->draftStore->has($draft_key)) {
      // Drop the pending draft; the live override (if any) is untouched.
      $this->draftStore->delete($draft_key);
      return;
    }
    $live = $this->languageConfigOverride->getOverride($langcode, $entity->getConfigDependencyName());
    if (!$live->isNew()) {
      $live->delete();
    }
    $this->loadBaseline($entity, $langcode)?->delete();
  }

  /**
   * Whether a pending (unpublished) draft exists for the entity + language.
   */
  public function hasDraft(ComponentTreeConfigEntityBase $entity, string $langcode): bool {
    return $this->draftStore->has($this->draftKey($entity, $langcode));
  }

  /**
   * Whether a published (live) language override exists for the entity.
   */
  public function hasOverride(ComponentTreeConfigEntityBase $entity, string $langcode): bool {
    return !$this->languageConfigOverride
      ->getOverride($langcode, $entity->getConfigDependencyName())
      ->isNew();
  }

  /**
   * Whether the override is stale relative to the current source strings.
   *
   * Returns FALSE when no override exists (there is nothing to be stale).
   */
  public function isOutdated(ComponentTreeConfigEntityBase $entity, string $langcode): bool {
    if (!$this->hasOverride($entity, $langcode)) {
      return FALSE;
    }
    $baseline = $this->loadBaseline($entity, $langcode);
    // An override written outside this service (e.g. via the config_translation
    // UI, or before baselines existed) has no baseline; treat it as up-to-date
    // until the next publish through this service records one.
    if ($baseline === NULL) {
      return FALSE;
    }
    $stored = $baseline->getSource();
    \ksort($stored);
    $current = $this->sourceStringsByKey($entity);
    \ksort($current);
    return $stored !== $current;
  }

  /**
   * The source strings that changed since this override was last published.
   *
   * The config twin of ApiTranslateController::changedSourceStrings(): diffs
   * the current source against the published baseline. Empty when in sync, or
   * when no baseline exists (override written by another writer).
   *
   * @return array<string, string>
   *   Keyed by the editor's dotted row key; the value is the prior source text.
   *   Only keys whose source actually changed are present.
   */
  public function changedSourceStrings(ComponentTreeConfigEntityBase $entity, string $langcode): array {
    $baseline = $this->loadBaseline($entity, $langcode);
    if ($baseline === NULL) {
      return [];
    }
    $prev = $baseline->getSource();
    $now = $this->sourceStringsByKey($entity);
    return \array_filter($prev, static fn($v, $k): bool => ($now[$k] ?? '') !== $v, \ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Marks the override reviewed: refreshes the baseline to the current source.
   *
   * The config twin of "mark as reviewed" — the translator judged the existing
   * override still valid for the changed source. The override is untouched;
   * only the baseline advances, so isOutdated() flips to FALSE and the "Source
   * was…" diffs clear.
   */
  public function markReviewed(ComponentTreeConfigEntityBase $entity, string $langcode): void {
    if (!$this->hasOverride($entity, $langcode)) {
      return;
    }
    $this->writeBaseline($entity, $langcode);
  }

  /**
   * Computes a stable hash of the entity's source translatable strings.
   *
   * Used by the editor to detect a concurrent source edit (the live config
   * layout being changed while a translation is open).
   */
  public function sourceHash(ComponentTreeConfigEntityBase $entity): string {
    $strings = $this->sourceStringsByKey($entity);
    \ksort($strings);
    return \hash('xxh64', (string) \json_encode($strings));
  }

  /**
   * Records (or clears) the published source-strings baseline for staleness.
   *
   * Writes the current source strings when an override exists; deletes the
   * baseline when the override was emptied (nothing to be stale against).
   */
  private function writeBaseline(ComponentTreeConfigEntityBase $entity, string $langcode): void {
    $existing = $this->loadBaseline($entity, $langcode);
    if (!$this->hasOverride($entity, $langcode)) {
      $existing?->delete();
      return;
    }
    $source = $this->sourceStringsByKey($entity);
    if ($existing !== NULL) {
      $existing->setSource($source)->save();
      return;
    }
    $baseline = $this->baselineStorage()->create([
      'id' => ConfigBaseline::makeId($langcode, $entity->getConfigDependencyName()),
      'langcode' => $langcode,
      'config_name' => $entity->getConfigDependencyName(),
    ]);
    \assert($baseline instanceof ConfigBaseline);
    $baseline->setSource($source)->save();
  }

  /**
   * Loads the staleness baseline for an entity + language, if recorded.
   */
  private function loadBaseline(ComponentTreeConfigEntityBase $entity, string $langcode): ?ConfigBaseline {
    $baseline = $this->baselineStorage()->load(
      ConfigBaseline::makeId($langcode, $entity->getConfigDependencyName()),
    );
    \assert($baseline === NULL || $baseline instanceof ConfigBaseline);
    return $baseline;
  }

  /**
   * The storage handler for the baseline config entity.
   */
  private function baselineStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(ConfigBaseline::ENTITY_TYPE_ID);
  }

  /**
   * Reads the live (published) language override's data.
   *
   * @return array<string, mixed>
   *   The override data, or an empty array when no override exists.
   */
  private function liveOverrideData(ComponentTreeConfigEntityBase $entity, string $langcode): array {
    $override = $this->languageConfigOverride->getOverride($langcode, $entity->getConfigDependencyName());
    if ($override->isNew()) {
      return [];
    }
    $data = $override->getRawData();
    return \is_array($data) ? $data : [];
  }

  /**
   * The draft-store key for a config translation ("<config_name>:<langcode>").
   */
  private function draftKey(ComponentTreeConfigEntityBase $entity, string $langcode): string {
    return TranslationDraftStore::key($entity->getConfigDependencyName(), $langcode);
  }

  /**
   * Recursively collects translatable leaves with their path.
   *
   * @return list<array{path: list<string|int>, leaf: array}>
   *   Each entry pairs a leaf with the path of keys that reaches it.
   */
  private static function flattenLeaves(array $node, array $path): array {
    if (($node['#translate'] ?? FALSE) === TRUE) {
      return [['path' => $path, 'leaf' => $node]];
    }
    $leaves = [];
    foreach (Element::children($node) as $key) {
      if (\is_array($node[$key])) {
        $leaves = \array_merge($leaves, self::flattenLeaves($node[$key], [...$path, $key]));
      }
    }
    return $leaves;
  }

  /**
   * Recursively flattens an override `inputs` value to dotted-path => scalar.
   *
   * @return array<string, mixed>
   *   The scalar leaves, keyed by their dotted path.
   */
  private static function flattenScalars(mixed $value, array $path): array {
    if (!\is_array($value)) {
      return $path === [] ? [] : [\implode('.', $path) => $value];
    }
    $out = [];
    foreach ($value as $key => $child) {
      $out += self::flattenScalars($child, [...$path, $key]);
    }
    return $out;
  }

  /**
   * Normalizes a stored value to a string, dropping the empty placeholder.
   */
  private static function normalizeText(mixed $text): string {
    if (!\is_scalar($text)) {
      return '';
    }
    $text = (string) $text;
    return $text === self::EMPTY_PLACEHOLDER ? '' : $text;
  }

}
