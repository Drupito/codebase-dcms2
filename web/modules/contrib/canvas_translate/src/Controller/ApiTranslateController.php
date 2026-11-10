<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Controller;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas_translate\Service\ApprovalStore;
use Drupal\canvas_translate\Service\ConfigTranslatableGlue;
use Drupal\canvas_translate\Service\RichTextGuard;
use Drupal\canvas_translate\Service\TranslatableFieldGlue;
use Drupal\canvas_translate\Service\TranslationDraftStore;
use Drupal\canvas_translate\Service\TranslationStatus;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON API backing the Canvas Translate page extension.
 *
 * Translation drafts are stored in this module's own draft store
 * (\Drupal\canvas_translate\Service\TranslationDraftStore) — a per-language map
 * of the translator's target text — NOT in Canvas's auto-save store, so
 * Canvas's "publish all" workflow never publishes an in-progress translation.
 * Publishing applies the stored text onto the current stored translation as a
 * new revision (see ::applyDraftToTarget()). No Canvas core changes.
 *
 * @see \Drupal\canvas_translate\Service\TranslationDraftStore
 */
final class ApiTranslateController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Source text placeholder the extractor emits for empty optional props.
   *
   * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
   */
  private const string EMPTY_PLACEHOLDER = '∅';

  /**
   * Plain translatable string fields handled outside the component tree.
   */
  private const array SIMPLE_FIELDS = ['title', 'description'];

  /**
   * Every canvas_page field this module writes when publishing a translation.
   *
   * Writing to a non-translatable field lands on the values shared with the
   * source language and silently overwrites the original page. Canvas ships
   * canvas_page base fields as non-translatable (see tests/manual-setup.php),
   * and a config import can revert them, so translation degrades per field:
   * a non-translatable field is excluded everywhere (editor rows, drafts,
   * publish, completeness — see ::translatabilitySetup()), and when nothing
   * can be translated at all the endpoints refuse outright.
   */
  public const array WRITTEN_FIELDS = [...self::SIMPLE_FIELDS, 'components'];

  /**
   * Config entity types whose component trees are translatable.
   *
   * Also the `{type}` route parameter.
   *
   * @see \Drupal\canvas\Hook\ConfigTranslationHooks::TRANSLATABLE_ENTITY_TYPE_IDS
   */
  private const array CONFIG_TYPES = [
    ContentTemplate::ENTITY_TYPE_ID,
    PageRegion::ENTITY_TYPE_ID,
    PageVariant::ENTITY_TYPE_ID,
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly TranslationDraftStore $draftStore,
    private readonly TranslatableFieldGlue $glue,
    private readonly TranslationStatus $translationStatus,
    private readonly AccountInterface $currentUser,
    private readonly ConfigTranslatableGlue $configGlue,
    private readonly Connection $database,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ApprovalStore $approvalStore,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly RichTextGuard $richText,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get(TranslationDraftStore::class),
      $container->get(TranslatableFieldGlue::class),
      $container->get(TranslationStatus::class),
      $container->get('current_user'),
      $container->get(ConfigTranslatableGlue::class),
      $container->get('database'),
      $container->get('module_handler'),
      $container->get(ApprovalStore::class),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get(RichTextGuard::class),
      $container->get('config.factory'),
    );
  }

  /**
   * Page-translation setup state, derived from field and bundle config.
   *
   * 'fields' are the written fields that are NOT translatable — a normal site
   * configuration choice, silently excluded from translation everywhere
   * (editor rows, drafts, publish, completeness) so the rest of the page
   * stays translatable without ever overwriting source values. 'blocked'
   * means translation cannot work at all — content translation is not enabled
   * for the canvas_page bundle, or no written field is translatable — and the
   * endpoints refuse outright.
   *
   * Shared with the status report check (RequirementsHooks).
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $field_definitions
   *   Field definitions for the canvas_page bundle.
   * @param array<string, array{translatable?: bool}> $bundle_info
   *   Bundle info for the canvas_page entity type (content_translation sets
   *   'translatable' per bundle).
   *
   * @return array{blocked: bool, fields: list<string>}
   *   Whether page translation is blocked, and the written fields that are not
   *   translatable.
   */
  public static function translatabilitySetup(array $field_definitions, array $bundle_info): array {
    $fields = \array_values(\array_filter(
      self::WRITTEN_FIELDS,
      static fn (string $name): bool => isset($field_definitions[$name]) && !$field_definitions[$name]->isTranslatable(),
    ));
    $present = \array_values(\array_intersect(self::WRITTEN_FIELDS, \array_keys($field_definitions)));
    $blocked = empty($bundle_info[Page::ENTITY_TYPE_ID]['translatable']) || $fields === $present;
    return ['blocked' => $blocked, 'fields' => $fields];
  }

  /**
   * Runs ::translatabilitySetup() for the current site configuration.
   *
   * @return array{blocked: bool, fields: list<string>}
   *   See ::translatabilitySetup().
   */
  private function currentTranslatabilitySetup(): array {
    return self::translatabilitySetup(
      $this->entityFieldManager->getFieldDefinitions(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID),
      $this->entityTypeBundleInfo->getBundleInfo(Page::ENTITY_TYPE_ID),
    );
  }

  /**
   * A structured 409 when page translation cannot work at all.
   *
   * NULL unless setup is 'blocked' (individual non-translatable fields degrade
   * gracefully instead — see ::translatabilitySetup()). The `code` key lets
   * the SPA render setup instructions instead of a raw error string.
   */
  private function translatabilitySetupError(): ?JsonResponse {
    $setup = $this->currentTranslatabilitySetup();
    if (!$setup['blocked']) {
      return NULL;
    }
    return new JsonResponse([
      'error' => 'Translation is not enabled for Canvas pages, so translating would overwrite the original language. Enable it under Content language settings.',
      'code' => 'translation_not_enabled',
    ], Response::HTTP_CONFLICT);
  }

  /**
   * The draft-store key for a page translation ("canvas_page:{id}:{langcode}").
   */
  private static function draftKey(int|string $page_id, string $langcode): string {
    return TranslationDraftStore::key(
      TranslationDraftStore::contentSubject(Page::ENTITY_TYPE_ID, $page_id),
      $langcode,
    );
  }

  /**
   * Lists translatable content with per-language status (dashboard).
   *
   * With `?id=`, lists only that page (the per-page overview) and no config
   * items.
   */
  public function items(Request $request): JsonResponse {
    $search = \mb_strtolower(\trim((string) $request->query->get('search', '')));
    $filter_langcode = (string) $request->query->get('langcode', '');
    $filter_status = (string) $request->query->get('status', '');
    $filter_id = $request->query->getInt('id');

    $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);
    // ponytail: loads every accessible page + filters in PHP. Fine for the page
    // counts Canvas sites have today; add a query-level status index if a site
    // ever has tens of thousands of pages.
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC');
    if ($filter_id > 0) {
      $query->condition('id', $filter_id);
    }
    $ids = $query->execute();
    /** @var \Drupal\canvas\Entity\Page[] $pages */
    $pages = $ids ? $storage->loadMultiple($ids) : [];
    $draft_keys = $this->translationStatus->loadDraftKeys();
    $approval_keys = $this->translationStatus->loadApprovalKeys();

    $items = [];
    foreach ($pages as $page) {
      if (!$page->access('update')) {
        continue;
      }
      $label = (string) $page->label();
      if ($search !== '' && !\str_contains(\mb_strtolower($label), $search)) {
        continue;
      }
      $translations = $this->translationStatus->forEntity($page, $draft_keys, $approval_keys);
      $this->addPercentages($page, $translations);
      $this->addChangedCounts($page, $translations);
      if (!$this->matchesStatusFilter($translations, $page->getUntranslated()->language()->getId(), $filter_langcode, $filter_status)) {
        continue;
      }
      $items[] = [
        'kind' => 'content',
        'type' => Page::ENTITY_TYPE_ID,
        'id' => (int) $page->id(),
        'label' => $label,
        'sourceLangcode' => $page->getUntranslated()->language()->getId(),
        'translations' => $translations,
      ];
    }

    // Append config items (Content Templates + Page Regions), so the dashboard
    // is a single unified list.
    if ($filter_id === 0) {
      foreach ($this->configItems($search, $filter_langcode, $filter_status) as $config_item) {
        $items[] = $config_item;
      }
    }

    return new JsonResponse([
      'languages' => $this->languages(),
      'total' => \count($items),
      'items' => $items,
      // TRUE when page translation cannot work at all — the SPA hides the
      // whole workspace behind setup instructions.
      'translationBlocked' => $this->currentTranslatabilitySetup()['blocked'],
    ]);
  }

  /**
   * Lists translatable config entities with per-language status + percent.
   *
   * @return list<array{kind: string, type: string, id: string, label: string, sourceLangcode: string, translations: array}>
   *   The dashboard items.
   */
  private function configItems(string $search, string $filter_langcode, string $filter_status): array {
    $items = [];
    foreach (self::CONFIG_TYPES as $entity_type_id) {
      // Canvas renders page regions only when no page variant resolves. Once
      // a default variant is set (the region-to-variant migration sets one),
      // regions never render, so their translations are not worth offering.
      // @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber
      if ($entity_type_id === 'page_region' && $this->configFactory->get('canvas.settings')->get('default_page_variant')) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      \assert($storage instanceof ConfigEntityStorageInterface);
      // Override-free: language config overrides would otherwise make the
      // source strings we diff translations against the *overridden* ones.
      foreach ($storage->loadMultipleOverrideFree() as $entity) {
        if (!$entity instanceof ComponentTreeConfigEntityBase) {
          continue;
        }
        if (!$entity->access('update')) {
          continue;
        }
        $source_strings = \array_filter(
          $this->configGlue->sourceStringsByKey($entity),
          static fn(string $v): bool => $v !== '',
        );
        // Nothing translatable → don't surface it in the dashboard.
        if ($source_strings === []) {
          continue;
        }
        $label = (string) $entity->label();
        if ($search !== '' && !\str_contains(\mb_strtolower($label), $search)) {
          continue;
        }
        $source_langcode = $entity->language()->getId();
        $translations = $this->configTranslations($entity, $source_strings);
        if (!$this->matchesStatusFilter($translations, $source_langcode, $filter_langcode, $filter_status)) {
          continue;
        }
        $items[] = [
          'kind' => 'config',
          'type' => $entity_type_id,
          'id' => (string) $entity->id(),
          'label' => $label,
          'sourceLangcode' => $source_langcode,
          'translations' => $translations,
        ];
      }
    }
    return $items;
  }

  /**
   * Computes per-language status + percent for one config entity.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeConfigEntityBase $entity
   *   The config entity being translated.
   * @param array<string, string> $source_strings
   *   The non-empty source strings (the completeness denominator).
   *
   * @return array<string, array{status: string, hasDraft: bool, changed: ?int, percent: int}>
   *   Keyed by langcode.
   */
  private function configTranslations(ComponentTreeConfigEntityBase $entity, array $source_strings): array {
    $source_langcode = $entity->language()->getId();
    $total = \count($source_strings);
    $result = [];
    foreach (\array_keys($this->languageManager->getLanguages()) as $langcode) {
      if ($langcode === $source_langcode) {
        $result[$langcode] = [
          'status' => TranslationStatus::SOURCE,
          'hasDraft' => FALSE,
          'changed' => NULL,
          'percent' => 100,
        ];
        continue;
      }
      $has_draft = $this->configGlue->hasDraft($entity, $langcode);
      if (!$has_draft && !$this->configGlue->hasOverride($entity, $langcode)) {
        $result[$langcode] = [
          'status' => TranslationStatus::NOT_TRANSLATED,
          'hasDraft' => FALSE,
          'changed' => NULL,
          'percent' => 0,
        ];
        continue;
      }
      // Percent is computed from the draft-aware target strings, so a pending
      // draft's completeness is what the dashboard reflects.
      $target_strings = $this->configGlue->targetStringsByKey($entity, $langcode);
      $filled = 0;
      foreach (\array_keys($source_strings) as $key) {
        if (($target_strings[$key] ?? '') !== '') {
          $filled++;
        }
      }
      $percent = $total > 0 ? (int) \round($filled / $total * 100) : 100;
      // Precedence mirrors content: draft > outdated > up_to_date.
      if ($has_draft) {
        $status = TranslationStatus::DRAFT;
      }
      elseif ($this->configGlue->isOutdated($entity, $langcode)) {
        $status = TranslationStatus::OUTDATED;
      }
      else {
        $status = TranslationStatus::UP_TO_DATE;
      }
      $result[$langcode] = [
        'status' => $status,
        'hasDraft' => $has_draft,
        'approved' => $has_draft && $this->approvalStore->isApproved(self::configApprovalKey($entity, $langcode)),
        'changed' => NULL,
        'changedStrings' => $status === TranslationStatus::OUTDATED
          ? \count($this->configGlue->changedSourceStrings($entity, $langcode))
          : NULL,
        'percent' => $percent,
      ];
    }
    return $result;
  }

  /**
   * Returns the side-by-side field list for one page + target language.
   */
  public function getTranslation(ContentEntityInterface $canvas_page, string $langcode): JsonResponse {
    if (($error = $this->validateLangcode($langcode, $canvas_page)) !== NULL) {
      return $error;
    }
    if (!$canvas_page->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }
    // Blocked setup (translation not enabled at all) refuses outright; fields
    // that are individually non-translatable are normal site configuration and
    // are silently excluded from the rows below.
    $setup = $this->currentTranslatabilitySetup();
    if ($setup['blocked']) {
      return $this->translatabilitySetupError();
    }
    $excluded = $setup['fields'];
    $stored = $this->loadUnchanged($canvas_page);
    $source = $stored->getUntranslated();

    // When a draft exists, its flat values map is the authoritative source of
    // target values (overlaid per row below). With no draft we read the stored
    // translation and apply source fallback. $target is the stored translation,
    // used for non-draft values.
    $draft_values = $this->draftStore->get(self::draftKey($stored->id(), $langcode));
    $target = $stored->hasTranslation($langcode) ? $stored->getTranslation($langcode) : NULL;

    // When the translation has drifted from the source, derive the source as it
    // was at the translation's last publish so the editor can show, per field,
    // what changed ("Source was: …"). Computed live from revision history — see
    // ::baselineSource() — and only for outdated docs, so the revision walk
    // runs only when there is actually drift to surface.
    $status = $this->singleStatus($stored, $langcode);
    // Per-field "what changed in the source since the last publish": key =>
    // prior source text, only for keys that actually changed (see
    // ::changedSourceStrings()). Empty when in sync or never published.
    $changed = $status === TranslationStatus::OUTDATED ? $this->changedSourceStrings($stored, $langcode) : [];

    $groups = [];

    // Simple string fields → a "Page" group.
    $page_rows = [];
    foreach (self::SIMPLE_FIELDS as $field_name) {
      if (!$source->hasField($field_name) || \in_array($field_name, $excluded, TRUE)) {
        continue;
      }
      $source_val = (string) ($source->get($field_name)->value ?? '');
      $stored_val = $target ? (string) ($target->get($field_name)->value ?? '') : '';
      $row = [
        'key' => $field_name,
        'label' => (string) $source->get($field_name)->getFieldDefinition()->getLabel(),
        'format' => NULL,
        'source' => $source_val,
        // Draft value when a draft exists; otherwise the stored value with
        // source fallback (a target equal to the source is an untranslated
        // prop, shown empty so the editor surfaces what still needs
        // translating).
        'target' => self::targetText($field_name, $draft_values, $stored_val, $source_val),
      ];
      if (isset($changed[$field_name])) {
        $row['sourceWas'] = $changed[$field_name];
      }
      // The currently-published target, so the review screen can diff "what
      // changes on publish". Only meaningful while a draft is pending, and only
      // when it actually differs from the draft value.
      if ($draft_values !== NULL) {
        $published = self::targetText($field_name, NULL, $stored_val, $source_val);
        if ($published !== $row['target']) {
          $row['targetWas'] = $published;
        }
      }
      $page_rows[] = $row;
    }
    if ($page_rows) {
      $groups[] = ['component' => NULL, 'label' => (string) $this->t('Page'), 'rows' => $page_rows];
    }

    // Component tree fields. The stored translation's strings supply targets
    // when there is no draft, and the published baseline for the "what changes
    // on publish" diff when there is one, so extract them whenever a target
    // translation exists. A non-translatable components field is excluded
    // entirely (writing it would overwrite the source's layout content).
    $source_data = \in_array('components', $excluded, TRUE)
      ? []
      : $this->glue->extractTranslatableData($source->get('components'));
    $target_data = $target && $source_data !== [] ? $this->glue->extractTranslatableData($target->get('components')) : [];
    foreach (Element::children($source_data) as $delta) {
      $item = $source->get('components')->get($delta);
      \assert($item instanceof ComponentTreeItem);
      $rows = [];
      $target_subtree = $target_data[$delta] ?? [];
      foreach (self::flattenLeaves($source_data[$delta], []) as $found) {
        ['path' => $path, 'leaf' => $leaf] = $found;
        $target_leaf = NestedArray::getValue($target_subtree, $path);
        $source_text = self::normalizeText($leaf['#text'] ?? '');
        $stored_text = self::normalizeText($target_leaf['#text'] ?? '');
        $key = 'components.' . $delta . '.' . \implode('.', $path);
        $row = [
          'key' => $key,
          'label' => (string) ($leaf['#label'] ?? \end($path)),
          'format' => $leaf['#format'] ?? NULL,
          'source' => $source_text,
          // Draft value when a draft exists; else stored value with source
          // fallback (target == source means untranslated → show empty).
          'target' => self::targetText($key, $draft_values, $stored_text, $source_text),
        ];
        if (isset($changed[$key])) {
          $row['sourceWas'] = $changed[$key];
        }
        if ($draft_values !== NULL) {
          $published = self::targetText($key, NULL, $stored_text, $source_text);
          if ($published !== $row['target']) {
            $row['targetWas'] = $published;
          }
        }
        $rows[] = $row;
      }
      if ($rows) {
        $component = $item->getComponent();
        $groups[] = [
          'component' => [
            'uuid' => $item->getUuid(),
            'label' => (string) ($item->getLabel() ?? $component?->label() ?? $item->getComponentId()),
          ],
          'label' => (string) ($component?->label() ?? $item->getComponentId()),
          'rows' => $rows,
        ];
      }
    }

    return new JsonResponse([
      'id' => (int) $stored->id(),
      'label' => (string) $stored->label(),
      'sourceLangcode' => $source->language()->getId(),
      'targetLangcode' => $langcode,
      'languages' => $this->languages(),
      'status' => $status,
      // Whether the pending draft has been approved (review pipeline step).
      'approved' => $draft_values !== NULL && $this->approvalStore->isApproved(self::draftKey($stored->id(), $langcode)),
      // A fingerprint of the source's translatable strings, so the editor can
      // detect a concurrent source edit on its next autosave (see
      // ::saveDraft()).
      'sourceHash' => $this->sourceHash($source),
      'aiAvailable' => $this->aiAvailable(),
      'groups' => $this->richText->secureGroups($groups),
    ]);
  }

  /**
   * Saves target values as a langcode-keyed auto-save draft.
   */
  public function saveDraft(ContentEntityInterface $canvas_page, string $langcode, Request $request): JsonResponse {
    if (($error = $this->validateLangcode($langcode, $canvas_page)) !== NULL) {
      return $error;
    }
    $setup = $this->currentTranslatabilitySetup();
    if ($setup['blocked']) {
      return $this->translatabilitySetupError();
    }
    $body = \json_decode($request->getContent(), TRUE);
    $values = \is_array($body['values'] ?? NULL) ? $body['values'] : [];

    $stored = $this->loadUnchanged($canvas_page);
    // Access is checked against the target translation (seeded from source when
    // it does not yet exist). We do NOT persist this entity — only the values
    // map.
    $translation = $stored->hasTranslation($langcode)
      ? $stored->getTranslation($langcode)
      : $stored->addTranslation($langcode, $stored->toArray());
    if (!$translation->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }

    // Persist the translator's target text in this module's own draft store.
    // The page, its structure, and every other translation are untouched until
    // publish — and the draft is invisible to Canvas's publish workflow. Only
    // the editor's string keys are kept — minus non-translatable (excluded)
    // fields, which can never be written; structure/fallback are applied at
    // read and publish time (see ::getTranslation() and
    // ::applyDraftToTarget()).
    $excluded = $setup['fields'];
    $values = \array_filter(
      $values,
      static fn($v, $k): bool => \is_string($v)
        && ((\in_array($k, self::SIMPLE_FIELDS, TRUE) && !\in_array($k, $excluded, TRUE))
          || (!\in_array('components', $excluded, TRUE) && \str_starts_with((string) $k, 'components.'))),
      \ARRAY_FILTER_USE_BOTH,
    );
    $key = self::draftKey($stored->id(), $langcode);
    // A rich-text row in a format the user may not use stays as it is, as in
    // core's own forms.
    $source = $stored->getUntranslated();
    $current = [];
    $draft_values = $this->draftStore->get($key);
    $stored_strings = $stored->hasTranslation($langcode) ? $this->stringsByKey($translation) : [];
    foreach ($this->stringsByKey($source) as $row_key => $source_text) {
      $current[$row_key] = self::targetText($row_key, $draft_values, $stored_strings[$row_key] ?? '', $source_text);
    }
    $values = $this->richText->lockValues($values, $this->formatsByKey($source), $current);
    $this->draftStore->set($key, $values);
    // Editing a draft invalidates any prior approval — it must be reviewed
    // again.
    $this->approvalStore->clear($key);

    // Concurrent-edit detection: the editor sends the source fingerprint it
    // loaded with; if the source changed in Canvas since then, tell it to
    // refetch and review (see editor's autosave handler). No server-side state
    // — the basis travels with the request.
    $base = \is_string($body['baseSourceHash'] ?? NULL) ? $body['baseSourceHash'] : NULL;
    $source_hash = $this->sourceHash($stored->getUntranslated());

    return new JsonResponse([
      'savedKey' => $key,
      'dataHash' => \hash('xxh64', (string) \json_encode($values)),
      'entityType' => Page::ENTITY_TYPE_ID,
      'entityId' => (int) $stored->id(),
      'status' => $this->singleStatus($stored, $langcode),
      'sourceChanged' => $base !== NULL && $base !== $source_hash,
      'sourceHash' => $source_hash,
    ]);
  }

  /**
   * Discards the draft for one page + target language.
   */
  public function discardDraft(ContentEntityInterface $canvas_page, string $langcode): JsonResponse {
    if (($error = $this->validateLangcode($langcode, $canvas_page)) !== NULL) {
      return $error;
    }
    $stored = $this->loadUnchanged($canvas_page);
    $probe = $stored->hasTranslation($langcode)
      ? $stored->getTranslation($langcode)
      : $stored->addTranslation($langcode, $stored->toArray());
    if (!$probe->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }
    $key = self::draftKey($stored->id(), $langcode);
    if (!$this->draftStore->has($key)) {
      return new JsonResponse(['error' => 'No draft to discard.'], Response::HTTP_NOT_FOUND);
    }
    $this->draftStore->delete($key);
    $this->approvalStore->clear($key);
    return new JsonResponse(['status' => $this->singleStatus($stored, $langcode)]);
  }

  /**
   * Marks a pending draft approved (the middle step before batch publish).
   *
   * Approval is per-draft review state: a reviewer has read the diff and judged
   * the draft ready. It is cleared automatically when the draft changes, is
   * discarded, or is published, so an approval always refers to the exact draft
   * that was reviewed.
   */
  public function approve(ContentEntityInterface $canvas_page, string $langcode): JsonResponse {
    if (($error = $this->validateLangcode($langcode, $canvas_page)) !== NULL) {
      return $error;
    }
    $stored = $this->loadUnchanged($canvas_page);
    if (!$canvas_page->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }
    $key = self::draftKey($stored->id(), $langcode);
    if (!$this->draftStore->has($key)) {
      return new JsonResponse(['error' => 'There is no draft to approve.'], Response::HTTP_BAD_REQUEST);
    }
    $this->approvalStore->approve($key);
    return new JsonResponse(['approved' => TRUE, 'status' => $this->singleStatus($stored, $langcode)]);
  }

  /**
   * Withdraws approval for a pending draft (no-op when not approved).
   */
  public function unapprove(ContentEntityInterface $canvas_page, string $langcode): JsonResponse {
    if (($error = $this->validateLangcode($langcode, $canvas_page)) !== NULL) {
      return $error;
    }
    $stored = $this->loadUnchanged($canvas_page);
    if (!$canvas_page->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }
    $this->approvalStore->clear(self::draftKey($stored->id(), $langcode));
    return new JsonResponse(['approved' => FALSE, 'status' => $this->singleStatus($stored, $langcode)]);
  }

  /**
   * Publishes the draft translation as a new revision.
   *
   * Unlike Canvas's auto-save publish endpoint, this writes the translated
   * field values onto the target translation *unconditionally* — it does not
   * diff them against the source. That diff is wrong for translations: a value
   * that legitimately equals the source (e.g. after "copy source", or an
   * untranslated brand name) would otherwise be treated as "no change" and the
   * translation would publish empty. Non-translatable fields stay shared with
   * the source, so the symmetric-translation constraint is satisfied.
   *
   * UPSTREAM(canvas): publish() / publishBatch() / applyDraftToTarget() /
   * prepareNewRevision() reimplement Canvas's auto-save publish (revision
   * creation, validation, batch transaction, draft cleanup).
   *
   * PARTIALLY ADDRESSED, STILL BLOCKED as of #3591596 (ADR #13): Canvas now has
   * a native translation publish (ApiAutoSaveController::post() →
   * applyAutoSaveTranslationSnapshots()). It diffs the draft against the stored
   * *target* translation — not the source — so the original blocker
   * (source-equal values dropped on an EXISTING translation) is gone. Three new
   * blockers keep us off it:
   *   1. New-translation copy-source regresses. For a not-yet-existing
   *      translation it diffs the snapshot against the default (source), so
   *      source-equal fields are skipped and a brand-new "copy source"
   *      translation publishes empty — exactly what our unconditional write
   *      avoids. @see CanvasTranslateWorkflowTest::testCopySourcePublishesNonEmptyTranslation
   *   2. Sibling grouping. Canvas now forces every pending translation of an
   *      entity to publish together (includeSiblingTranslationAutoSaves); our
   *      per-language publish() / publishBatch() publish languages
   *      independently.
   *   3. It reconciles only the edited translation's component version on
   *      publish, assuming the editor flow already propagated the rest.
   *   Minimal Canvas change: a "translation publish" mode that (a) writes the
   *   stored draft onto the target WITHOUT the source diff even when the target
   *   is new, and (b) allows publishing a single language. Then these methods
   *   collapse into a call to Canvas's publisher. Net here: ~−150 lines.
   */
  public function publish(ContentEntityInterface $canvas_page, string $langcode): JsonResponse {
    if (($error = $this->validateLangcode($langcode, $canvas_page)) !== NULL) {
      return $error;
    }
    if (($error = $this->translatabilitySetupError()) !== NULL) {
      return $error;
    }
    $stored = $this->loadUnchanged($canvas_page);
    $target = self::resolveTarget($stored, $langcode);
    if (!$target->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }
    if (!$this->applyDraftToTarget($stored, $target)) {
      return new JsonResponse(['error' => 'No draft to publish.'], Response::HTTP_NOT_FOUND);
    }
    $this->prepareNewRevision($stored);

    $violations = $stored->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      return new JsonResponse(['error' => 'The translation could not be published.', 'details' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
    // Save in a transaction so a mid-save failure rolls back cleanly instead of
    // leaving a partial revision (matches ::publishBatch()).
    $transaction = $this->database->startTransaction();
    try {
      $stored->save();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      return new JsonResponse(['error' => 'The translation could not be published.', 'details' => [$e->getMessage()]], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    $key = self::draftKey($stored->id(), $langcode);
    $this->draftStore->delete($key);
    $this->approvalStore->clear($key);

    return new JsonResponse(['status' => $this->singleStatus($stored, $langcode)]);
  }

  /**
   * Marks an outdated translation reviewed without rewriting its values.
   *
   * "Mark as reviewed" — the translator judged the existing translation still
   * valid for the changed source. Unlike ::publish() there is no draft to
   * apply; we only flip content_translation_outdated to FALSE and cut a new
   * default revision. That revision becomes the next ::baselineSource() anchor,
   * so a later source edit measures "what changed" from now, not from the last
   * content publish.
   */
  public function markReviewed(ContentEntityInterface $canvas_page, string $langcode): JsonResponse {
    if (($error = $this->validateLangcode($langcode, $canvas_page)) !== NULL) {
      return $error;
    }
    $stored = $this->loadUnchanged($canvas_page);
    if (!$stored->hasTranslation($langcode)) {
      return new JsonResponse(['error' => 'There is no translation to mark as reviewed.'], Response::HTTP_NOT_FOUND);
    }
    $target = $stored->getTranslation($langcode);
    if (!$target->access('update')) {
      return new JsonResponse(['error' => 'You do not have permission to translate this page.'], Response::HTTP_FORBIDDEN);
    }
    // Nothing to clear (e.g. translatability not enabled) — report current
    // state.
    if (!$target->hasField('content_translation_outdated')) {
      return new JsonResponse(['status' => $this->singleStatus($stored, $langcode)]);
    }
    $target->set('content_translation_outdated', FALSE);
    $this->prepareNewRevision($stored);

    $violations = $stored->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      return new JsonResponse(['error' => 'The translation could not be saved.', 'details' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
    $transaction = $this->database->startTransaction();
    try {
      $stored->save();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      return new JsonResponse(['error' => 'The translation could not be saved.', 'details' => [$e->getMessage()]], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(['status' => $this->singleStatus($stored, $langcode)]);
  }

  /**
   * Publishes a reviewed set of drafts in one all-or-nothing operation.
   *
   * Mirrors Canvas's "publish all" (see ApiAutoSaveController::post): every
   * requested draft is applied and validated first; if any fails, nothing is
   * saved. Items are grouped by page so multiple languages of one page produce
   * a single new revision. Reuses the same source-safe per-language logic as
   * ::publish().
   *
   * Body: `{ "items": [ { "id": <page id>, "langcode": "<lc>" }, ... ] }`.
   *
   * @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  public function publishBatch(Request $request): JsonResponse {
    $body = \json_decode($request->getContent(), TRUE);
    $items = \is_array($body['items'] ?? NULL) ? $body['items'] : [];

    // Group requested langcodes by page id (deduplicated).
    $by_page = [];
    foreach ($items as $item) {
      $id = (int) ($item['id'] ?? 0);
      $langcode = (string) ($item['langcode'] ?? '');
      if ($id > 0 && $langcode !== '') {
        $by_page[$id][$langcode] = TRUE;
      }
    }
    if ($by_page === []) {
      return new JsonResponse(['error' => 'No items to publish.'], Response::HTTP_BAD_REQUEST);
    }
    if (($error = $this->translatabilitySetupError()) !== NULL) {
      return $error;
    }

    $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);
    $known_languages = $this->languageManager->getLanguages();
    // Prepared, validated pages keyed by id: ['stored' => Page, 'targets' =>
    // [lc => target]].
    $prepared = [];
    $errors = [];
    foreach ($by_page as $id => $langcodes) {
      $stored = $storage->loadUnchanged($id);
      if (!$stored instanceof Page) {
        foreach (\array_keys($langcodes) as $langcode) {
          $errors[] = ['id' => $id, 'langcode' => $langcode, 'detail' => 'Page not found.'];
        }
        continue;
      }
      $source_langcode = $stored->getUntranslated()->language()->getId();
      $targets = [];
      foreach (\array_keys($langcodes) as $langcode) {
        if (!\array_key_exists($langcode, $known_languages) || $langcode === $source_langcode) {
          $errors[] = ['id' => $id, 'langcode' => $langcode, 'detail' => 'Cannot publish this language.'];
          continue;
        }
        $target = self::resolveTarget($stored, $langcode);
        if (!$target->access('update')) {
          $errors[] = [
            'id' => $id,
            'langcode' => $langcode,
            'detail' => 'You do not have permission to translate this page.',
          ];
          continue;
        }
        if (!$this->applyDraftToTarget($stored, $target)) {
          $errors[] = ['id' => $id, 'langcode' => $langcode, 'detail' => 'No draft to publish.'];
          continue;
        }
        $targets[$langcode] = $target;
      }
      if ($targets !== []) {
        $this->prepareNewRevision($stored);
        $prepared[$id] = ['stored' => $stored, 'targets' => $targets];
      }
    }

    // Validate every prepared page before saving any (all-or-nothing).
    foreach ($prepared as $id => ['stored' => $stored]) {
      $violations = $stored->validate();
      foreach ($violations as $violation) {
        $errors[] = ['id' => $id, 'detail' => (string) $violation->getMessage()];
      }
    }
    if ($errors !== []) {
      return new JsonResponse(['error' => 'No translations were published.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // Save all pages in one transaction; roll back on any failure.
    $transaction = $this->database->startTransaction();
    try {
      foreach ($prepared as ['stored' => $stored]) {
        $stored->save();
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      return new JsonResponse([
        'error' => 'The translations could not be published.',
        'details' => [['detail' => $e->getMessage()]],
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    // Clear the published drafts only after a successful save.
    foreach ($prepared as $id => ['targets' => $targets]) {
      foreach (\array_keys($targets) as $langcode) {
        $batch_key = self::draftKey($id, $langcode);
        $this->draftStore->delete($batch_key);
        $this->approvalStore->clear($batch_key);
      }
    }

    $results = [];
    foreach ($prepared as $id => ['stored' => $stored, 'targets' => $targets]) {
      foreach (\array_keys($targets) as $langcode) {
        $results[] = ['id' => $id, 'langcode' => $langcode, 'status' => $this->singleStatus($stored, $langcode)];
      }
    }
    return new JsonResponse(['published' => \count($results), 'items' => $results]);
  }

  /**
   * Resolves (loading or seeding from source) one translation of a page.
   *
   * A new translation is seeded from the source so untranslated props keep
   * their source value (the no-data-loss invariant).
   */
  private static function resolveTarget(Page $stored, string $langcode): ContentEntityInterface {
    return $stored->hasTranslation($langcode)
      ? $stored->getTranslation($langcode)
      : $stored->addTranslation($langcode, $stored->toArray());
  }

  /**
   * Applies a stored draft's translated text onto the target translation.
   *
   * Writes the draft's non-empty values onto the **current** stored structure
   * of $target (seeded from source for a new translation, loaded from storage
   * for an existing one). Applying onto current structure — rather than copying
   * a stored snapshot's whole component tree — preserves a structural change
   * (e.g. a component version update) published since the draft was saved.
   *
   * Only non-empty translatable values are written, with no diff against the
   * source: a value that legitimately equals the source (after "copy source",
   * or an untranslated brand name) is still written, so the translation never
   * publishes empty. Untranslated props keep their source-seeded value; the
   * structural and non-translatable columns stay shared with the source,
   * satisfying the symmetric-translation constraint.
   *
   * Mutates $target in place; does not set revision metadata or save (the
   * caller does that once per page, so multiple languages share a single
   * revision).
   *
   * @return bool
   *   TRUE if a draft was applied; FALSE if there is no draft to publish.
   */
  private function applyDraftToTarget(Page $stored, ContentEntityInterface $target): bool {
    $values = $this->draftStore->get(self::draftKey($stored->id(), $target->language()->getId()));
    if ($values === NULL) {
      return FALSE;
    }
    // Never write a non-translatable field: its storage is shared with the
    // source language, so the write would overwrite the original page. Such
    // fields are excluded from the editor and stripped from drafts upstream;
    // this is the last line of defense at the write site.
    foreach (self::SIMPLE_FIELDS as $field_name) {
      if (($values[$field_name] ?? '') !== ''
        && $target->hasField($field_name)
        && $target->getFieldDefinition($field_name)->isTranslatable()) {
        $target->set($field_name, $values[$field_name]);
      }
    }
    if ($target->get('components')->getFieldDefinition()->isTranslatable()) {
      $this->glue->applyValuesToComponents($values, $target->get('components'));
    }

    if ($target instanceof EntityPublishedInterface) {
      $target->setPublished();
    }
    // The translation is now in sync with the source.
    if ($target->hasField('content_translation_outdated')) {
      $target->set('content_translation_outdated', FALSE);
    }
    if ($target->hasField('content_translation_source')) {
      $source = $target->get('content_translation_source');
      // A translation seeded from the source (addTranslation) copies the
      // source's content_translation_source, which defaults to `und` (a real
      // value), so isEmpty() is FALSE. Treat that `und` sentinel as "not yet
      // set".
      if ($source->isEmpty() || $source->value === LanguageInterface::LANGCODE_NOT_SPECIFIED) {
        $target->set('content_translation_source', $stored->getUntranslated()->language()->getId());
      }
    }
    return TRUE;
  }

  /**
   * Flags the stored page for a new default revision attributed to the user.
   */
  private function prepareNewRevision(Page $stored): void {
    $stored->setNewRevision(TRUE);
    $stored->isDefaultRevision(TRUE);
    if ($revision_user = $stored->getEntityType()->getRevisionMetadataKey('revision_user')) {
      $stored->set($revision_user, $this->currentUser->id());
    }
  }

  /**
   * Returns the side-by-side field list for one config entity + language.
   *
   * Config "translations" are language config overrides, not entity
   * translations, so this reads the active config as the source and the `fr`
   * (etc.) override as the target.
   */
  public function getConfigTranslation(string $type, string $id, string $langcode): JsonResponse {
    [$entity, $error] = $this->loadConfigEntity($type, $id, $langcode);
    if ($error !== NULL) {
      return $error;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    $groups = $this->configGlue->groups($entity, $langcode);
    // Whether the pending draft has been approved (review pipeline step).
    $approved = $this->configGlue->hasDraft($entity, $langcode)
      && $this->approvalStore->isApproved(self::configApprovalKey($entity, $langcode));
    return new JsonResponse([
      'kind' => 'config',
      'type' => $type,
      'id' => (string) $entity->id(),
      'label' => (string) $entity->label(),
      'sourceLangcode' => $entity->language()->getId(),
      'targetLangcode' => $langcode,
      'languages' => $this->languages(),
      'status' => $this->singleConfigStatus($entity, $langcode),
      'approved' => $approved,
      // Fingerprint of the source config's translatable strings, so the editor
      // can detect a concurrent source edit on its next autosave.
      'sourceHash' => $this->configGlue->sourceHash($entity),
      'aiAvailable' => $this->aiAvailable(),
      'groups' => $this->richText->secureGroups($groups),
    ]);
  }

  /**
   * Writes posted target strings into the language override.
   *
   * There is no autosave/draft layer for config in v1: a save writes straight
   * into the language override, and publish is a confirmation no-op. Writing a
   * target string changes only the override; the active (source) config is
   * untouched.
   */
  public function saveConfigTranslation(string $type, string $id, string $langcode, Request $request): JsonResponse {
    [$entity, $error] = $this->loadConfigEntity($type, $id, $langcode);
    if ($error !== NULL) {
      return $error;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    $body = \json_decode($request->getContent(), TRUE);
    $values = \is_array($body['values'] ?? NULL) ? $body['values'] : [];
    // A rich-text row in a format the user may not use stays as it is, as in
    // core's own forms.
    $rows = \array_merge(...\array_column($this->configGlue->groups($entity, $langcode), 'rows'));
    $values = $this->richText->lockValues($values, \array_column($rows, 'format', 'key'), \array_column($rows, 'target', 'key'));
    $this->configGlue->save($entity, $langcode, $values);
    // Editing a draft invalidates any prior approval — it must be reviewed
    // again.
    $this->approvalStore->clear(self::configApprovalKey($entity, $langcode));
    $base = \is_string($body['baseSourceHash'] ?? NULL) ? $body['baseSourceHash'] : NULL;
    $source_hash = $this->configGlue->sourceHash($entity);
    return new JsonResponse([
      'entityType' => $type,
      'entityId' => (string) $entity->id(),
      'status' => $this->singleConfigStatus($entity, $langcode),
      'sourceChanged' => $base !== NULL && $base !== $source_hash,
      'sourceHash' => $source_hash,
    ]);
  }

  /**
   * Discards a config translation draft (or removes the translation).
   *
   * Mirrors content's discardDraft: a pending draft is dropped, reverting to
   * the last published state. When nothing is pending, the published override
   * itself is removed.
   */
  public function discardConfigTranslation(string $type, string $id, string $langcode): JsonResponse {
    [$entity, $error] = $this->loadConfigEntity($type, $id, $langcode);
    if ($error !== NULL) {
      return $error;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    if (!$this->configGlue->hasDraft($entity, $langcode) && !$this->configGlue->hasOverride($entity, $langcode)) {
      return new JsonResponse(['error' => 'No translation to discard.'], Response::HTTP_NOT_FOUND);
    }
    $this->configGlue->discard($entity, $langcode);
    $this->approvalStore->clear(self::configApprovalKey($entity, $langcode));
    return new JsonResponse(['status' => $this->singleConfigStatus($entity, $langcode)]);
  }

  /**
   * Publishes a config translation.
   *
   * Flushes the staged draft to the live override. Any posted values are
   * staged first (so an explicit publish carries the latest edits), then the
   * draft is published. Canvas's batch publish endpoint cannot flush a
   * translation-only override, so this drives the single-override publish.
   *
   * @see \Drupal\canvas_translate\Service\ConfigTranslatableGlue::publish()
   */
  public function publishConfigTranslation(string $type, string $id, string $langcode, Request $request): JsonResponse {
    [$entity, $error] = $this->loadConfigEntity($type, $id, $langcode);
    if ($error !== NULL) {
      return $error;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    $body = \json_decode($request->getContent(), TRUE);
    $values = \is_array($body['values'] ?? NULL) ? $body['values'] : [];
    if ($values !== []) {
      $this->configGlue->save($entity, $langcode, $values);
    }
    $this->configGlue->publish($entity, $langcode);
    $this->approvalStore->clear(self::configApprovalKey($entity, $langcode));
    return new JsonResponse(['status' => $this->singleConfigStatus($entity, $langcode)]);
  }

  /**
   * Marks a pending config translation draft approved.
   *
   * The config twin of ::approve(); see it for the approval lifecycle.
   */
  public function approveConfig(string $type, string $id, string $langcode): JsonResponse {
    [$entity, $error] = $this->loadConfigEntity($type, $id, $langcode);
    if ($error !== NULL) {
      return $error;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    if (!$this->configGlue->hasDraft($entity, $langcode)) {
      return new JsonResponse(['error' => 'There is no draft to approve.'], Response::HTTP_BAD_REQUEST);
    }
    $this->approvalStore->approve(self::configApprovalKey($entity, $langcode));
    return new JsonResponse(['approved' => TRUE, 'status' => $this->singleConfigStatus($entity, $langcode)]);
  }

  /**
   * Withdraws approval for a pending config translation draft.
   */
  public function unapproveConfig(string $type, string $id, string $langcode): JsonResponse {
    [$entity, $error] = $this->loadConfigEntity($type, $id, $langcode);
    if ($error !== NULL) {
      return $error;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    $this->approvalStore->clear(self::configApprovalKey($entity, $langcode));
    return new JsonResponse(['approved' => FALSE, 'status' => $this->singleConfigStatus($entity, $langcode)]);
  }

  /**
   * The approval-store key for a config translation.
   *
   * Aligned with its draft key.
   */
  private static function configApprovalKey(ComponentTreeConfigEntityBase $entity, string $langcode): string {
    return TranslationDraftStore::key($entity->getConfigDependencyName(), $langcode);
  }

  /**
   * Marks a config override reviewed: clears outdated without rewriting it.
   *
   * The config twin of ::markReviewed() — the translator judged the existing
   * override still valid for the changed source. Advances the staleness
   * baseline to the current source; the live override is untouched.
   */
  public function markConfigReviewed(string $type, string $id, string $langcode): JsonResponse {
    [$entity, $error] = $this->loadConfigEntity($type, $id, $langcode);
    if ($error !== NULL) {
      return $error;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    $this->configGlue->markReviewed($entity, $langcode);
    return new JsonResponse(['status' => $this->singleConfigStatus($entity, $langcode)]);
  }

  /**
   * Loads + access-checks a translatable config entity for the given type.
   *
   * @return array{0: ?ComponentTreeConfigEntityBase, 1: ?\Symfony\Component\HttpFoundation\JsonResponse}
   *   A tuple of [entity, error]; exactly one is non-NULL.
   */
  private function loadConfigEntity(string $type, string $id, string $langcode): array {
    if (!\in_array($type, self::CONFIG_TYPES, TRUE)) {
      return [NULL, new JsonResponse(['error' => 'Unknown config type.'], Response::HTTP_NOT_FOUND)];
    }
    if (!\array_key_exists($langcode, $this->languageManager->getLanguages())) {
      return [NULL, new JsonResponse(['error' => 'Unknown language.'], Response::HTTP_BAD_REQUEST)];
    }
    $storage = $this->entityTypeManager->getStorage($type);
    \assert($storage instanceof ConfigEntityStorageInterface);
    $entity = $storage->loadOverrideFree($id);
    if (!$entity instanceof ComponentTreeConfigEntityBase) {
      return [NULL, new JsonResponse(['error' => 'Not found.'], Response::HTTP_NOT_FOUND)];
    }
    if ($langcode === $entity->language()->getId()) {
      $message = 'Cannot translate the source language. Edit the layout in the Canvas editor instead.';
      return [NULL, new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST)];
    }
    if (!$entity->access('update')) {
      $message = 'You do not have permission to translate this layout.';
      return [NULL, new JsonResponse(['error' => $message], Response::HTTP_FORBIDDEN)];
    }
    return [$entity, NULL];
  }

  /**
   * Computes the single-language status string for a config entity.
   */
  private function singleConfigStatus(ComponentTreeConfigEntityBase $entity, string $langcode): string {
    if ($this->configGlue->hasDraft($entity, $langcode)) {
      return TranslationStatus::DRAFT;
    }
    if (!$this->configGlue->hasOverride($entity, $langcode)) {
      return TranslationStatus::NOT_TRANSLATED;
    }
    return $this->configGlue->isOutdated($entity, $langcode)
      ? TranslationStatus::OUTDATED
      : TranslationStatus::UP_TO_DATE;
  }

  /**
   * Loads a fresh, unchanged copy of the page (ignores auto-save state).
   */
  private function loadUnchanged(ContentEntityInterface $canvas_page): Page {
    $entity = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID)->loadUnchanged($canvas_page->id());
    \assert($entity instanceof Page);
    return $entity;
  }

  /**
   * The source as it was when a translation was last published.
   *
   * Publishing a translation writes content_translation_outdated = FALSE on the
   * target (see ::applyDraftToTarget()); a later source edit writes TRUE (see
   * OutdatedFlagHooks). content_translation_outdated is revisionable, so the
   * most recent revision whose target translation has it FALSE holds the source
   * exactly as the translator last saw it. Diffing that against the current
   * source yields the per-field "what changed".
   *
   * (Until Canvas's symmetric translation landed we keyed off
   * isRevisionTranslationAffected(), but a source-only component edit now marks
   * EVERY translation's revision affected — synchronizing the shared
   * non-translatable columns — so that flag no longer isolates the last
   * publish.)
   *
   * @return \Drupal\canvas\Entity\Page|null
   *   The default (source) translation of that revision, or NULL if the
   *   translation has never been published (no in-sync revision).
   */
  private function baselineSource(Page $stored, string $langcode): ?Page {
    $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);
    $type = $stored->getEntityType();
    // ponytail: walks revisions newest-first until the last published (in-sync)
    // one — normally the 1st or 2nd. Add a langcode + outdated query condition
    // if a page ever accumulates thousands of revisions.
    $revision_ids = $storage->getQuery()
      ->allRevisions()
      ->condition($type->getKey('id'), $stored->id())
      ->sort($type->getKey('revision'), 'DESC')
      ->accessCheck(FALSE)
      ->execute();
    foreach (\array_keys($revision_ids) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if (!$revision instanceof Page || !$revision->hasTranslation($langcode)) {
        continue;
      }
      $translation = $revision->getTranslation($langcode);
      if ($translation->hasField('content_translation_outdated') && !$translation->get('content_translation_outdated')->value) {
        $untranslated = $revision->getUntranslated();
        \assert($untranslated instanceof Page);
        return $untranslated;
      }
    }
    return NULL;
  }

  /**
   * The source strings that changed since the translation was last published.
   *
   * Diffs the current source against ::baselineSource() (the source as it was
   * at the translation's last publish). Callers guard on outdated status; for
   * an in-sync or never-published translation the baseline is the current
   * source or NULL, so this returns an empty map either way.
   *
   * @return array<string, string>
   *   Keyed by the editor's dotted row key; the value is the prior source text.
   *   Only keys whose source actually changed are present.
   */
  private function changedSourceStrings(Page $stored, string $langcode): array {
    $baseline = $this->baselineSource($stored, $langcode);
    if ($baseline === NULL) {
      return [];
    }
    $prev = $this->stringsByKey($baseline);
    $now = $this->stringsByKey($stored->getUntranslated());
    return \array_filter($prev, static fn($v, $k): bool => ($now[$k] ?? '') !== $v, \ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Recomputes status for a single language (post-save / post-discard).
   */
  private function singleStatus(Page $page, string $langcode): string {
    $fresh = $this->loadUnchanged($page);
    return $this->translationStatus->forEntity($fresh, $this->translationStatus->loadDraftKeys())[$langcode]['status'];
  }

  /**
   * Whether an item passes the dashboard status filter.
   *
   * With no status filter, everything passes. With a language + status, that
   * language's status must match. With a status but no language (the UI allows
   * this), the item passes when ANY non-source language has that status.
   *
   * @param array<string, array{status: string}> $translations
   *   Per-language status entries, keyed by langcode.
   * @param string $source_langcode
   *   The item's source language, skipped when matching any language.
   * @param string $filter_langcode
   *   The language filter, or '' for any language.
   * @param string $filter_status
   *   The status filter, or '' for any status.
   */
  private function matchesStatusFilter(array $translations, string $source_langcode, string $filter_langcode, string $filter_status): bool {
    if ($filter_status === '') {
      return TRUE;
    }
    if ($filter_langcode !== '') {
      $entry = $translations[$filter_langcode] ?? NULL;
      return $entry !== NULL && $entry['status'] === $filter_status;
    }
    foreach ($translations as $langcode => $entry) {
      if ($langcode !== $source_langcode && $entry['status'] === $filter_status) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Rejects the source langcode and unknown languages.
   */
  private function validateLangcode(string $langcode, ContentEntityInterface $canvas_page): ?JsonResponse {
    if (!\array_key_exists($langcode, $this->languageManager->getLanguages())) {
      return new JsonResponse(['error' => 'Unknown language.'], Response::HTTP_BAD_REQUEST);
    }
    if ($langcode === $canvas_page->getUntranslated()->language()->getId()) {
      return new JsonResponse(['error' => 'Cannot translate the source language. Edit the page in the Canvas editor instead.'], Response::HTTP_BAD_REQUEST);
    }
    return NULL;
  }

  /**
   * Adds a completeness `percent` to each per-language status entry.
   *
   * The percentage is filled target strings divided by source strings.
   *
   * Ponytail: extracts translatable strings per page and per existing
   * translation on every dashboard load. Fine for the page counts Canvas sites
   * have today; memoize per revision if a site grows to thousands of pages.
   */
  private function addPercentages(Page $page, array &$translations): void {
    $source = $page->getUntranslated();
    // Only strings that actually have source content count toward completeness;
    // an empty source field has nothing to translate.
    $source_strings = \array_filter($this->stringsByKey($source), static fn(string $v): bool => $v !== '');
    $total = \count($source_strings);
    $source_langcode = $source->language()->getId();
    foreach ($translations as $langcode => &$state) {
      if ($langcode === $source_langcode) {
        $state['percent'] = 100;
      }
      elseif (!$page->hasTranslation($langcode)) {
        $state['percent'] = 0;
      }
      else {
        $target_strings = $this->stringsByKey($page->getTranslation($langcode));
        $filled = 0;
        foreach ($source_strings as $key => $source_value) {
          $target_value = $target_strings[$key] ?? '';
          // Translated = a non-empty target that differs from the source. A
          // target equal to the source is an untranslated (source-fallback)
          // prop.
          if ($target_value !== '' && $target_value !== $source_value) {
            $filled++;
          }
        }
        $state['percent'] = $total > 0 ? (int) \round($filled / $total * 100) : 100;
      }
    }
  }

  /**
   * Adds `changedStrings` to each per-language status entry.
   *
   * The count of source strings changed since the last publish, non-NULL only
   * for outdated languages.
   *
   * Ponytail: runs the ::baselineSource() revision-walk per OUTDATED cell on
   * dashboard load. Bounded because it skips in-sync/untranslated cells (the
   * majority) and the walk stops at the first in-sync revision. Memoize per
   * (revision id, langcode) if a site ever accrues many outdated cells.
   */
  private function addChangedCounts(Page $page, array &$translations): void {
    foreach ($translations as $langcode => &$state) {
      $state['changedStrings'] = ($state['status'] ?? NULL) === TranslationStatus::OUTDATED
        ? \count($this->changedSourceStrings($page, $langcode))
        : NULL;
    }
  }

  /**
   * Flattens every translatable string of an entity to a key => text map.
   *
   * The keys match the dotted keys used by the editor and ::saveDraft(), so the
   * completeness denominator is exactly the set of rows shown in the editor.
   *
   * @return array<string, string>
   *   Source text, keyed by dotted key.
   */
  private function stringsByKey(ContentEntityInterface $entity): array {
    $strings = [];
    // Non-translatable fields are excluded from translation everywhere
    // (editor rows, drafts, publish), so keep them out of the denominator
    // too — completeness, the source hash, and outdated diffs all key off
    // this map and must match what the editor actually offers.
    foreach (self::SIMPLE_FIELDS as $field_name) {
      if ($entity->hasField($field_name) && $entity->getFieldDefinition($field_name)->isTranslatable()) {
        $strings[$field_name] = self::normalizeText($entity->get($field_name)->value ?? '');
      }
    }
    if (!$entity->get('components')->getFieldDefinition()->isTranslatable()) {
      return $strings;
    }
    $data = $this->glue->extractTranslatableData($entity->get('components'));
    foreach (Element::children($data) as $delta) {
      foreach (self::flattenLeaves($data[$delta], []) as $found) {
        $key = 'components.' . $delta . '.' . \implode('.', $found['path']);
        $strings[$key] = self::normalizeText($found['leaf']['#text'] ?? '');
      }
    }
    return $strings;
  }

  /**
   * Each component-tree row's text format, keyed like ::stringsByKey().
   *
   * @return array<string, ?string>
   *   The text format machine name, or NULL for plain text.
   */
  private function formatsByKey(ContentEntityInterface $entity): array {
    $formats = [];
    $data = $this->glue->extractTranslatableData($entity->get('components'));
    foreach (Element::children($data) as $delta) {
      foreach (self::flattenLeaves($data[$delta], []) as $found) {
        $formats['components.' . $delta . '.' . \implode('.', $found['path'])] = $found['leaf']['#format'] ?? NULL;
      }
    }
    return $formats;
  }

  /**
   * A stable fingerprint of an entity's translatable source strings.
   *
   * Same inputs OutdatedFlagHooks watches; used by the editor to detect a
   * concurrent source edit (see ::saveDraft()). ksort so key order never
   * perturbs the hash.
   */
  private function sourceHash(ContentEntityInterface $source): string {
    $strings = $this->stringsByKey($source);
    \ksort($strings);
    return \hash('xxh64', (string) \json_encode($strings));
  }

  /**
   * Whether the optional AI translation submodule is enabled.
   *
   * Checking module presence (not the `ai` service) keeps this module free of
   * any dependency on Drupal AI. The editor shows AI controls only when TRUE.
   */
  private function aiAvailable(): bool {
    return $this->moduleHandler->moduleExists('canvas_translate_ai');
  }

  /**
   * The site's languages, for the editor's language pickers.
   *
   * @return list<array{id: string, name: string, direction: string, isDefault: bool}>
   *   One entry per language.
   */
  private function languages(): array {
    $data = [];
    foreach ($this->languageManager->getLanguages() as $language) {
      $data[] = [
        'id' => $language->getId(),
        'name' => $language->getName(),
        'direction' => $language->getDirection(),
        'isDefault' => $language->isDefault(),
      ];
    }
    return $data;
  }

  /**
   * Recursively collects translatable leaves with their path.
   *
   * @return list<array{path: list<string|int>, leaf: array}>
   *   The leaves, in document order.
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
   * Resolves a row's target text for the editor.
   *
   * When a draft exists, its stored value is authoritative (it already holds
   * the editor's post-fallback state). Otherwise the stored translation value
   * is used, with source fallback: a target equal to the source is an
   * untranslated prop and is shown empty.
   *
   * @param string $key
   *   The row's dotted key.
   * @param array<string, string>|null $draft_values
   *   The draft values map, or NULL when no draft exists.
   * @param string $stored_text
   *   The stored translation's value for this row.
   * @param string $source_text
   *   The source value for this row.
   *
   * @return string
   *   The target text to show in the editor.
   */
  private static function targetText(string $key, ?array $draft_values, string $stored_text, string $source_text): string {
    if ($draft_values !== NULL) {
      return (string) ($draft_values[$key] ?? '');
    }
    return $stored_text === $source_text ? '' : $stored_text;
  }

  /**
   * Casts a stored value to text, mapping the empty placeholder to "".
   */
  private static function normalizeText(mixed $text): string {
    if (!\is_scalar($text)) {
      return '';
    }
    $text = (string) $text;
    return $text === self::EMPTY_PLACEHOLDER ? '' : $text;
  }

}
