# Design

## Problem

Translation drafts live in Canvas's auto-save store (`canvas.auto_save`). Canvas's
publish workflow (`ApiAutoSaveController::post()`) publishes every pending
auto-save for an entity:
- content: `includeSiblingTranslationAutoSaves()` pulls in every pending
  translation of the entity;
- config: `groupConfigEntityAutoSaves()` publishes each staged
  `StagedLanguageConfigOverride` with its base config entity.

So publishing the default-language content also publishes any open translation.

## Decision: a workspace-owned draft store

`canvas_translate` stores its translator text in its own store, keyed
`{kind}:{id}:{langcode}`, holding the raw dotted-key → text **values map**. Because
the drafts are not in `canvas.auto_save`, Canvas's publish workflow cannot see or
publish them.

The store wraps Drupal core's public `keyvalue` API. This is not a fork of Canvas
auto-save; it is a different store with a deliberately different contract (Canvas's
publish must NOT see it). The choice to *not* reuse Canvas auto-save is recorded
with an `UPSTREAM(canvas):` marker on the store: the minimal upstream fix is an
auto-save/publish that can exclude or namespace module-owned translation drafts;
when that lands, the store and marker are deleted and the module returns to
auto-save.

### Why store the values map, not an entity/staged override

Storing the raw values (not a reconstructed entity snapshot or a staged override)
is what makes publish safe against concurrent structural changes — see below.

## Critical interaction: symmetric-translation structural propagation (ADR #13)

Canvas ADR #13 (`web/modules/contrib/canvas/docs/adr/0013-symmetric-translation-propagation-on-component-instance-update.md`)
runs the component instance updater on **every** translation when a component
version changes, and persists the result as auto-saves that publish with the
default. That structural propagation is correct and required, and it continues to
work unchanged: it operates on the stored translations / live overrides, never on
our private text drafts.

Consequence: a private text draft does **not** receive structural propagation, so
it can be structurally stale relative to current storage. Therefore:

- **Content publish** must apply the draft's translated values onto the *current*
  `loadUnchanged()` translation via `TranslatableFieldGlue::setTranslations()` —
  writing only translatable leaves — rather than copying the draft entity's whole
  `components` field (which would regress structure that propagation just fixed).
  This is the `applyDraftToTarget()` change.
- **Config publish** writes the live `LanguageConfigOverride` from the stored
  values; structural re-derivation of the override is Canvas's job on its own
  publish and works from the live (published) data, never our pending text.

Staleness remains visible to translators through the existing
`content_translation_outdated` flag (content) and the source-hash staleness signal
(config).

## Config: drop `StagedLanguageConfigOverride` for our drafts

`StagedLanguageConfigOverride` is precisely the mechanism Canvas's publish uses to
ship config translations, and it provides a draft view shared with the Canvas
editor. We give up that shared view (which is the leak path) and instead:
- `save()` writes the values map to the store;
- reads overlay the stored values on the live `LanguageConfigOverride`;
- `publish()` writes the live override directly
  (`languageConfigOverride->getOverride(...)->setData(...)->save()`), records the
  source hash, and deletes an emptied override;
- `hasDraft()`/`discard()` operate on the store.

## Out of scope

- Migrating in-flight `canvas.auto_save` translation drafts into the new store
  (module is pre-release).
- Surfacing translation drafts in Canvas's pending-changes tray (the opposite
  direction; explicitly not wanted here).
