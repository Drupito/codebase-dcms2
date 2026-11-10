# AGENTS.md — Canvas Translate

In-house translation of Canvas content, delivered as a Canvas page extension.
Backend in `src/`, the React SPA in `extension/`, tests in `tests/`.

## Specs are the source of truth

Behavioral requirements live in `openspec/specs/<capability>/spec.md` as
OpenSpec requirements (RFC-2119 **MUST** language with `#### Scenario:` blocks) —
**not** prose docs. They define what this module must do. The capabilities:

- `content-translation` — workspace access, dashboard, side-by-side editor,
  draft autosave, **source-fallback (no data loss)**, copy source, publish,
  discard, draft cleanup on delete.
- `translation-status` — status states, completeness percentage, outdated tracking.
- `config-translation` — content templates + page regions via config language overrides.
- `translation-preview` — live target-language preview.
- `canvas-core-alignment` — **governance: minimize what we override.** Prefer
  public Canvas/core APIs; no forking; no dependency on hidden/test/`@internal`
  Canvas surfaces. Every unavoidable fork/reinvention MUST carry a greppable
  `UPSTREAM(canvas):` marker (what's forked, the minimal upstream fix, what we
  delete when it lands). Applies to **all** work in this module. Harvest the
  ledger with `grep -rn "UPSTREAM(canvas)" --include=*.php --include=*.yml`.

`README.md` is human onboarding (setup/build). The specs are the contract.

## Keeping specs current — required

When you change behavior, you **MUST** keep the specs in sync in the same change:

1. **Changing or adding behavior** → update the matching `### Requirement:` /
   `#### Scenario:` (or add one). For non-trivial work, use the OpenSpec change
   workflow first (`/opsx:propose`), then apply.
2. **Removing behavior** → remove or amend the requirement; don't leave specs
   describing code that no longer exists.
3. **Always** run `npx -y @fission-ai/openspec@latest validate --specs` and make
   it pass before committing.
4. A spec change with no test, or a behavior change with no spec update, is
   incomplete. Every requirement should be backed by a test in `tests/`.

If a requested change conflicts with an existing requirement, surface the
conflict and update the requirement deliberately — do not silently diverge.

## Invariants — do not regress

These are hard-won; breaking them caused real bugs:

- **Translation drafts live in the module's own store, never Canvas's auto-save.**
  `TranslationDraftStore` holds the translator's per-language text (content **and**
  config), keyed `{subject}:{langcode}`, out of `canvas.auto_save` — so Canvas's
  "publish all" (`ApiAutoSaveController::post()`) never publishes an in-progress
  translation as a side effect of publishing the source. Do **not** move drafts
  back into `AutoSaveManager` (it re-exposes them to Canvas's publish via
  `includeSiblingTranslationAutoSaves()` / `groupConfigEntityAutoSaves()`); see the
  `UPSTREAM(canvas):` marker on `TranslationDraftStore`. Config translations stage
  in the same store and write the live `LanguageConfigOverride` only on the
  module's own publish.
- **No data loss.** A translation is seeded from source; only **non-empty** target
  values are written. Never write `''` over a prop that has source content
  (empties lose content and break required props). Untranslated == source value;
  the editor shows such props as untranslated.
- **Publish writes unconditionally onto the current structure.** Do not reintroduce
  a source-diff on publish (Canvas's diff-based auto-save publish drops fields equal
  to source, emptying copy-source / new translations). `applyDraftToTarget()` applies
  the stored text onto the **freshly loaded** translation (via
  `TranslatableFieldGlue::applyValuesToComponents()`), so a structural change made
  after the draft was saved is preserved, never regressed. Use
  `ApiTranslateController::publish()` (single draft) or `::publishBatch()` (a
  reviewed set). Batch publish is all-or-nothing (one DB transaction, grouped by page
  so multiple languages of one page = one revision); content drafts are published
  from the SPA's **Review & publish** view, not from the page editor.
- **Source and other translations are never touched** when publishing one language.
- **`canvas_page` base fields must be translatable** (Canvas ships them off) — see
  README / `tests/manual-setup.php`.

## Verify

- Backend: `php -l`, kernel tests
  `ddev exec "vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/contrib/canvas_translate/tests/src/Kernel/"`.
- SPA: `cd extension && npm run type-check && npm run build` (commit the built
  `extension/dist/index.global.js`).
- E2E: `tests/e2e/` (Playwright against the live ddev site).
