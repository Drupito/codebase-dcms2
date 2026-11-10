## Why

Translation drafts (both page content and config layouts) are staged in Canvas's
auto-save store. Canvas's own "publish all" workflow
(`ApiAutoSaveController::post()`) therefore sweeps them in:

- **Content:** drafts keyed `canvas_page:{id}:{langcode}` are pulled in by
  `includeSiblingTranslationAutoSaves()`.
- **Config:** drafts staged as `StagedLanguageConfigOverride` are published by
  `groupConfigEntityAutoSaves()` when the base config entity is published.

The result: publishing a page's source content — or a content template / page
region — from the Canvas editor also publishes whatever in-progress translation a
translator happens to have open. Translators lose control over when their work
goes live, and a half-finished translation can ship.

This must not happen. A translation goes live only when published from the
translation workspace.

## What Changes

- `canvas_translate` keeps **all** its translation drafts (content and config) in
  a workspace-owned draft store, so Canvas's publish workflow neither lists nor
  publishes them.
- Canvas's symmetric-translation *structural* propagation (component version
  updates flowing to every translation, Canvas ADR #13) is **unchanged** — only
  the translator's text is withheld from Canvas's publish.
- Config translation gains an explicit, correct **draft → publish** model. The
  `config-translation` spec currently mis-describes a save as writing the live
  override directly; this corrects it: a save stages a workspace-owned draft and
  the live language override is written only on publish.
- Publishing a content translation applies the draft's translated values onto the
  **current** stored translation, so a structural change published in the meantime
  is preserved rather than regressed.

Note: in-flight drafts that exist in Canvas auto-save at deploy time are not
migrated into the new store (the module is pre-release); they remain unpublished
in auto-save and are simply no longer surfaced by the workspace.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `content-translation`: drafts become private to the workspace (excluded from
  Canvas's publish workflow); publish preserves concurrent structural changes.
- `config-translation`: storage becomes an explicit draft → publish model, and
  config drafts become private to the workspace.

## Impact

- Governance (`canvas-core-alignment`): the draft store uses Drupal core's public
  `keyvalue` API — not a fork of Canvas auto-save. Choosing not to use Canvas
  auto-save is a deliberate divergence, recorded with an `UPSTREAM(canvas):`
  marker on the new store naming the minimal upstream fix (an auto-save/publish
  that can exclude or namespace module-owned translation drafts) and what this
  module deletes once it lands. No `canvas-core-alignment` spec delta — its
  existing requirements already mandate the marker.
- Backend: new `src/Service/TranslationDraftStore.php`;
  `src/Controller/ApiTranslateController.php` and
  `src/Controller/PreviewController.php` move off `AutoSaveManager`;
  `src/Service/ConfigTranslatableGlue.php` stops staging
  `StagedLanguageConfigOverride` and writes the live override on publish;
  `src/Service/TranslationStatus.php` and `src/Hook/DraftCleanupHooks.php` use the
  new store. `canvas_translate.services.yml` gains the store. **No Canvas core
  changes.**
- Tests: `tests/src/Kernel/CanvasTranslateWorkflowTest.php` and
  `ConfigTranslateWorkflowTest.php` gain isolation coverage (publishing the source
  via Canvas's `ApiAutoSaveController::post()` leaves translation drafts intact)
  plus a structural-propagation-then-publish case.
