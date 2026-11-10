# Canvas Translate — architecture

How the module is wired, with autosave as the spine. Source of truth is the
code; this explains the *shape* so you don't have to reverse-engineer it from
nine files. Where the code and the older prose docs disagree, this notes it.

## 1. The big picture

Canvas Translate is a **Canvas page extension**: a self-contained React SPA
served in an isolated iframe at `/canvas/app/canvas_translate`, talking to a
Drupal JSON API on the same origin. There is **no Canvas core patch**, and —
deliberately — **translation drafts do NOT use Canvas's auto-save store**: they
live in this module's own store so Canvas's "publish all" can never publish an
in-progress translation (see §2.3).

```
   EXTENSION IFRAME · React SPA  (static doc, no drupalSettings)
   ════════════════════════════════════════════════════════════
     HashRouter
       "/"                        ─►  Dashboard    (pages+config × lang, status)
       "/page/:id/:lang"          ─►  PageEditor   (content)
       "/config/:type/:id/:lang"  ─►  PageEditor   (config)
       "/review"                  ─►  ReviewPanel  (content only)
                                          │
                                          ▼
                  api.ts  ·  fetch · credentials · X-CSRF-Token
                                          │
                          JSON over HTTP  │  (same origin)
                                          ▼
   DRUPAL BACKEND · no Canvas core patch
   ════════════════════════════════════════════════════════════
                       ApiTranslateController
                      (one controller, both domains)
               content │                       │ config
                       ▼                       ▼
         TranslatableFieldGlue          ConfigTranslatableGlue
         TranslationStatus              (language config overrides)
                       │                       │
                       └───────────┬───────────┘
                                   ▼
              TranslationDraftStore  →  canvas_translate.drafts keyvalue
              (this module; NOT Canvas's canvas.auto_save store)
                                   │ on publish (from the workspace only)
                   ┌───────────────┴───────────────┐
                   ▼                               ▼
           New entity revision           Live language config override
           (content)                     (config)
```

The single most important thing to internalize: **there are two translation
domains — content and config — that share one UI, one controller, and one draft
store, but differ in what a "translation" *is* and where publishing lands.**

| | Content (`canvas_page`) | Config (`content_template`, `page_variant`, `page_region`) |
|---|---|---|
| What a "translation" *is* | a real entity translation | a `language.config.<lang>.*` override |
| Draft store | per-langcode values map in `TranslationDraftStore` | per-config-name values map in `TranslationDraftStore` |
| Publish target | new entity revision | live config override |
| Staleness signal | core `content_translation_outdated` (revisionable field) | published source strings in a `canvas_translate_baseline` config entity |
| Preview pane | yes (full themed render) | no (no standalone render) |
| Routed at | `/page/:id/:lang` | `/config/:type/:id/:lang` |

Both reuse the same `PageEditor` component and the same module-owned draft store
(`TranslationDraftStore`) — they just key and publish into it differently:

```
  CONTENT  edit ──►  canvas_translate.drafts  ──publish──►  new entity revision
   canvas_page       key: canvas_page:{id}:{lang}           (content_translation_outdated = FALSE)
                     = dotted-key → text values map

  CONFIG   edit ──►  canvas_translate.drafts  ──publish──►  live language config override
   template/region   key: {config_name}:{lang}              language.config.<lang>.*  (or deleted)
                     = dotted-key → text values map

  Neither lives in canvas.auto_save, so Canvas's "publish all" never sees them.
```

## 2. Autosave — the spine

### 2.1 Frontend (`extension/components/PageEditor.tsx`)

State: `values` is a flat map of `dottedKey → targetString` for every editable
field. Autosave is a debounced effect, **not** an on-blur or on-button save.

```
 Translator   PageEditor (React)      useDebounce        Backend
     │              │                   (800 ms)   (Controller → TranslationDraftStore)
     │  edit field  │                      │                     │
     ├─────────────►│                      │                     │
     │              │ dirtyRef = true      │                     │
     │              │ saveState = 'saving' │                     │
     │              │ setValues() ────────►│                     │
     │              │               values changed               │
     │              │                      │  idle 800 ms        │
     │              │◄─────────────────────┤ debouncedValues     │
     │              │                      │                     │
     │              │  PATCH saveDraft(values, clientInstanceId)  │
     │              ├────────────────────────────────────────────►│
     │              │                      │   loadUnchanged + access-check translation
     │              │                      │   keep translatable string keys only
     │              │                      │   store values map "canvas_page:{id}:{lang}"
     │              │◄────────────────────────────────────────────┤  { status }
     │              │ dirtyRef = false; saveState = 'saved'; previewNonce++ (reload preview)
     │              │                      │                     │
     ╎ leave editor (Back / Review & publish)                    │
     ├─────────────►│ flushSave(): cancel pending debounce,       │
     │              │              PATCH the FULL values map now ─►│   (same save path,
     │              │              so the last <800 ms edit         │    synchronous)
     │              │              isn't lost on unmount            │
```

In words: each keystroke marks the editor dirty and resets the 800 ms timer;
when typing pauses, one PATCH sends the whole field map. `flushSave()` is the
escape hatch that forces that save immediately before the component unmounts.

Key mechanics, and *why each exists*:

- **`dirtyRef` gate** — the effect also runs on the initial load (when `load()`
  seeds `values`). `dirtyRef` ensures a save only fires after a genuine user
  edit, not on mount or on reload.
- **`flushSave()`** — the debounce is cancelled on unmount, so an edit made
  inside the last 800 ms would be lost when navigating away. `flushSave()`
  bypasses the debounce (cancels the pending run, PATCHes the full `values` map
  synchronously) and is called before **All pages**, **Review & publish**, and
  the back button. If you ever add a new way to leave the editor, it must call
  `flushSave()` first.
- **`cancelled` flag** in the effect cleanup — discards a stale in-flight save's
  result so a slow earlier save can't clobber a newer state.
- **Preview refresh** — on save, `previewNonce++` triggers a separate effect that
  `reload()`s the existing preview iframe in place and restores scroll position
  (rather than remounting, which would flash blank and jump to top).

The whole save is **last-write-wins on the full field map**. There's no
field-level diffing client side; every save sends every value.

### 2.2 Backend, content path (`ApiTranslateController::saveDraft`)

`PATCH /canvas-translate/api/items/canvas_page/{id}/{langcode}` →

1. `loadUnchanged()` the page; resolve (or seed from source) the target
   translation **only to access-check it** — it is never persisted here.
2. Keep just the editor's translatable string keys (`title`, `description`,
   `components.*`) and store that flat values map under
   `canvas_page:{id}:{langcode}` in `TranslationDraftStore`.

That is the whole write: no entity is saved, nothing touches `canvas.auto_save`.
Structure and source-fallback are applied later, at read (`getTranslation`
overlays the values map) and publish time. Returns `{ savedKey, dataHash, status }`;
the SPA uses `status`.

### 2.3 The draft store (`src/Service/TranslationDraftStore.php`)

A thin wrapper over Drupal core's `keyvalue` factory (collection
`canvas_translate.drafts`). It holds the translator's **text only** — a flat
`dottedKey → targetString` map — keyed `{subject}:{langcode}` (subject =
`canvas_page:{id}` for content, the config dependency name for config).

- **Why a bespoke store and not Canvas's auto-save?** Canvas's publish workflow
  (`ApiAutoSaveController::post()`) publishes *every* pending auto-save of an
  entity — content via `includeSiblingTranslationAutoSaves()`, config via
  `groupConfigEntityAutoSaves()`. If translation drafts lived in
  `canvas.auto_save`, publishing the default-language page/layout from the Canvas
  editor would also publish whatever translation a translator had open, with no
  per-entry opt-out. So drafts live here instead, invisible to that workflow.
  Recorded as an `UPSTREAM(canvas):` marker on the store: if Canvas's publish
  learns to exclude/namespace module-owned drafts, this store is deleted and the
  module returns to `AutoSaveManager`.
- **Lifetime:** drafts live until published, discarded, or their entity is deleted
  (`DraftCleanupHooks`, by key prefix). No TTL/GC.
- **Structural propagation still works.** Canvas ADR #13 propagates a component
  version update to every *stored* translation via its own auto-saves; that is
  untouched. Because our drafts carry only text (no component versions/structure),
  publish applies them onto the **current** stored translation — so a structural
  change made after a draft can't be regressed by it (§5, §6).

### 2.4 Backend, config path (`ConfigTranslatableGlue`)

A *published* config "translation" is a language config override
(`language.config.<lang>.*`), not an entity translation. In-progress edits get
the **same draft→publish layer** as content, in the **same module store**:

- `saveConfigTranslation` → `ConfigTranslatableGlue::save()` stores the editor's
  non-empty `component_tree.*` values under `{config_name}:{langcode}`; the live
  override is untouched.
- Reads overlay the stored draft values on the live override, so the editor and
  dashboard reflect the pending draft.
- `publishConfigTranslation` → `ConfigTranslatableGlue::publish()` writes the live
  `LanguageConfigOverride` directly from the stored values (deleting it when the
  draft is empty), clears the draft, and records the source-strings hash.

Because the draft is **not** a Canvas `StagedLanguageConfigOverride`, Canvas's
publish of the base config entity never publishes it.

> ✅ **The isolation invariant (the thing to know).** Neither content nor config
> drafts live in `canvas.auto_save`, so Canvas's "publish all" can never publish
> an in-progress translation. A translation goes live only via the workspace's own
> publish (`publish` / `publishBatch` / `publishConfigTranslation`). Do not move
> drafts back into `AutoSaveManager` — it re-exposes them to Canvas's publish.

### 2.5 Upgrade path: page regions → page variants (`PageRegionTranslationUpgrade`)

Canvas 1.11 turns a theme's page regions into one page variant, `theme_<theme>`,
and keeps the component UUIDs. Canvas copies the published translations (the
language overrides) but does not know about our draft and approval stores, and
copies nothing published to a region after its own update ran.

`PageRegionTranslationUpgrade::upgrade($variant)` finds the regions by the UUIDs
they share with the variant and, per language:

- moves region draft entries for those UUIDs into the variant draft (a draft the
  variant already had wins),
- approves the variant draft only when every moved region draft was approved
  and the variant had no draft of its own; clears the approval of any draft
  that changed,
- adds published region translations the variant override lacks (the variant's
  own translations win).

It runs from two places: `hook_page_variant_insert()` for a variant Canvas
creates while this module is installed (its update hook, or enabling Canvas for
a theme later), and `canvas_translate_post_update_0002` for a site that updated
Canvas while an older Canvas Translate was installed. Both are idempotent. A
variant created by a config import is left alone: the export is the source of
truth for its translations.

This module requires Canvas 1.11 (`canvas:canvas (>=1.11.0)`), so it can never
be updated ahead of Canvas: region translations cannot be edited with this
version and then drift from the variant Canvas builds later. The `canvas_translate_baseline` snapshot is
not copied; the next publish of the variant writes it.

## 3. Endpoint map (`canvas_translate.routing.yml`)

All require the `translate canvas content` permission; all mutations require an
`X-CSRF-Token` header; all are `no_cache`.

| Method + path | Controller | Purpose |
|---|---|---|
| `GET  /api/items` | `items` | dashboard list (content + config), status + percent |
| `GET  /api/items/canvas_page/{id}/{lang}` | `getTranslation` | side-by-side fields for the editor |
| `PATCH …/{id}/{lang}` | `saveDraft` | **autosave** a content draft |
| `DELETE …/{id}/{lang}` | `discardDraft` | drop a content draft |
| `POST …/{id}/{lang}/publish` | `publish` | publish one language → new revision |
| `POST /api/publish-batch` | `publishBatch` | all-or-nothing multi-page/lang publish |
| `GET/PATCH/DELETE /api/config/{type}/{id}/{lang}` | `…ConfigTranslation` | config get / **autosave** / discard |
| `POST …/config/.../{lang}/publish` | `publishConfigTranslation` | write live override from draft |
| `GET /preview/canvas_page/{id}/{lang}` | `PreviewController` | full themed draft render (iframe) |
| `GET /api/editor-assets?formats=…` | `EditorAssetsController` | core's CKEditor 5 settings + JS/CSS for text formats (honours `ajax_page_state[libraries]`) |
| `…/ai-translate` (POST) | `canvas_translate_ai` submodule | optional MT, writes into the draft |

## 4. Status model (`TranslationStatus`)

Five states, derived (never stored) by joining three cheap sources in memory:
existing translations, the `content_translation_outdated` flag, and the
draft-store keys.

```
   ┌────────────────┐   edit (autosave)    ┌───────┐   publish     ┌────────────┐
   │ not_translated │ ───────────────────► │ draft │ ────────────► │ up_to_date │
   └────────────────┘                      └───────┘               └────────────┘
            ▲                                ▲   ▲                        │
            │ discard                   edit │   │ edit                   │ source
            │ (no prior translation)         │   │                        │ strings
            └──────────── draft ◄────────────┘   └──── draft ◄────────────┤ change
                                                                          ▼
                                                    publish        ┌──────────┐
                                              ◄──────────────────  │ outdated │
                                                                   └──────────┘

   any committed state (up_to_date | outdated) → draft again on the next edit
   "source" = the default language; it sits outside this cycle and never changes
```

Meaning of each state:

| status | condition |
|---|---|
| `source` | the untranslated default language |
| `not_translated` | no translation and no draft |
| `draft` | a pending draft exists in the module store (**highest precedence**) |
| `outdated` | translation exists but source drifted (`content_translation_outdated = TRUE`) |
| `up_to_date` | translation exists, in sync with source |

Precedence is **draft > outdated > up_to_date** — a pending draft always shows as
`draft` regardless of the underlying translation state. `loadDraftKeys()` scans
the draft store **once** and is passed into per-entity computation to avoid an
N×language re-scan.

- **Content staleness** is core's revisionable `content_translation_outdated`
  field. Nothing in core sets it for Canvas (pages are edited in the Canvas
  editor, not the translation form), so `OutdatedFlagHooks` sets it on
  `entity_presave`: when a source page's *translatable* strings change (hash
  comparison), every existing translation is flagged outdated.
- **Config staleness** has no such field, and the override schema checker rejects
  unknown keys, so it's tracked **out-of-band** in a dedicated `canvas_translate_baseline`
  **config entity** (`src/Entity/ConfigBaseline.php`), id `{lang}.{config_name}`,
  which snapshots the published source strings. Outdated = stored strings ≠
  current source strings; the same snapshot yields per-field `sourceWas` for the
  editor, exactly like content. Being a config entity (not keyvalue) it **survives
  export/import**, and a config dependency on `{config_name}` removes it with the
  translated ContentTemplate/PageRegion.

## 5. The no-data-loss invariant

A translation is **seeded from the source** (`addTranslation(..., source->toArray())`),
and the module **only ever writes non-empty target values**. Consequences:

- Untranslated props fall back to the source value → the published page always
  renders fully and required props stay valid.
- A target equal to the source is treated as *untranslated* in the editor (shown
  empty) and in completeness math (not counted as filled).
- This is also why **publish writes the stored text onto the current translation,
  without diffing against the source** (`applyDraftToTarget` →
  `TranslatableFieldGlue::applyValuesToComponents`): a value that legitimately
  equals the source (a brand name, or after "copy source") must not be dropped as
  "no change", and applying onto the *current* structure preserves a structural
  change made since the draft (§6). The module publishes itself rather than reusing
  Canvas's diff-based auto-save publish — see `applyDraftToTarget()`.

## 6. Publish flows

- **Content, single language** (`publish`): apply draft onto target → mark
  published, clear outdated, set translation source → new revision (attributed
  to current user) → `validate()` → save in a DB transaction → delete the draft.
- **Content, batch** (`publishBatch`): group requested langcodes by page;
  prepare + validate **every** page first; if any violation, publish **none**
  (all-or-nothing); save all in one transaction; clear drafts only after success.
  Multiple languages of one page collapse into a single new revision.
- **Config** (`publishConfigTranslation`): stage any posted values, then
  `ConfigTranslatableGlue::publish()` reads the stored draft and writes the live
  `LanguageConfigOverride` directly (or deletes it when empty), clears the draft,
  and records the source hash.

The batch path is the load-bearing one (the Review queue is just a UI over it):

```
   POST /api/publish-batch          items: [ {id, lang}, ... ]
        │
        ▼
   group langcodes by page id
        │
        ▼
   per page: apply each draft onto its target translation + prepareNewRevision()
        │
        ▼
   ┌─────────────────────────────┐   any violation
   │ validate() EVERY page first │ ───────────────►  publish NONE   → 422 + details
   └──────────────┬──────────────┘
                  │ all valid
                  ▼
   save all pages in ONE DB transaction ──── exception ────►  rollBack  → 500
                  │ ok
                  ▼
   delete the published drafts from the module store
                  │
                  ▼
   200  { published, items[] }
```

The Review queue (`/review`, content only) is just a UI over `publishBatch`.

## 7. Known debts / sharp edges

These are documented inline as `UPSTREAM(canvas):` and `ponytail:` notes; the
load-bearing ones:

- **`TranslatableFieldGlue` is a ~146-line verbatim fork** of Canvas's
  `ComponentTreeFieldProcessor`, existing only because the original `extends` a
  tmgmt class and can't autoload without tmgmt. Highest-value upstream ask: move
  the tmgmt-free logic into a plain service and delete this fork.
- **Config staleness is invisible to the core `config_translation` UI** — the
  `canvas_translate_baseline` snapshot is exportable and refreshed on this
  module's publish path, but an override written by another writer (the core
  `config_translation` form, or Canvas's native staged override) won't refresh
  it, so the two can disagree. (Upgrade: subscribe to
  `LanguageConfigOverrideEvents::SAVE_OVERRIDE`.)
- **The module owns its draft store + publish** instead of using Canvas's
  auto-save, because Canvas's "publish all" would otherwise publish in-progress
  translations (no per-entry opt-out, §2.3) and its diff-based publish drops
  source-equal values on new translations (§5). Tracked by `UPSTREAM(canvas)`
  markers on `TranslationDraftStore` and `applyDraftToTarget()`; if Canvas's
  publish learns to exclude/namespace module-owned drafts, the store and most of
  these methods collapse back onto `AutoSaveManager`.
- **Dashboard + completeness load every page and extract strings in PHP per
  request** — fine for current site sizes; add query-level indexing if a site
  reaches thousands of pages/revisions.

## 8. Where things live

```
extension/                 React SPA (tsup → dist/index.global.js)
  api.ts                   all fetch() calls; CSRF; ApiError; clientInstanceId
  components/PageEditor.tsx the editor + debounced autosave + flushSave
  components/{Dashboard,ReviewPanel,FieldRow,RichTextField,...}.tsx
  editorAssets.ts          loads core's CKEditor 5 for a text format into the iframe
src/Controller/ApiTranslateController.php   the JSON API (content + config)
src/Controller/PreviewController.php        themed draft render for the iframe
src/Controller/EditorAssetsController.php   core text editor assets for the iframe
src/Service/TranslationDraftStore.php       the module's own draft store (content + config)
src/Service/TranslatableFieldGlue.php       component_tree string extract/write + apply (content)
src/Service/RichTextGuard.php               text format access + editor XSS filtering for rich rows
src/Service/ConfigTranslatableGlue.php      config draft/publish/staleness (own store + live override)
src/Service/TranslationStatus.php           per-language status derivation
src/Hook/OutdatedFlagHooks.php              flag translations outdated on source change
src/Hook/DraftCleanupHooks.php              clear drafts on page/config-entity delete
modules/canvas_translate_ai/                optional MT submodule
canvas_translate.routing.yml                endpoint → controller map
openspec/specs/*.md                         formal specs (content/config/status/preview)
```
