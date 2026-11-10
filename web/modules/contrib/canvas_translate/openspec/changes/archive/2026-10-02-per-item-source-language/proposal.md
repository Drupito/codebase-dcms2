## Why

The module assumed every item's source language is the site default language. On
a site whose default language changed (or where content is authored in a
non-default language), that assumption breaks the workflow: a page authored in
Hungarian on an English-default site never gets an English column in the
dashboard, so it can never be translated into the site default at all. For
config layouts the same assumption is baked into the backend — source strings,
completeness, and the "cannot translate the source language" guard are all
computed against the site default rather than the layout's own langcode.
Reported in [issue 3616274](https://www.drupal.org/project/canvas_translate/issues/3616274).

A second defect surfaced alongside it: config layouts were loaded with language
config overrides applied, so once a target-language override existed, the
"source" strings the module diffed translations against were the *translated*
ones — silently inflating completeness and hiding staleness.

## What Changes

- **An item's source language is the item's own language, not the site
  default.** Content pages already behaved this way
  (`$page->getUntranslated()->language()`); config layouts now match, using
  `$entity->language()` for listing, per-language status, the editor payload,
  and target validation.
- **The site default language becomes a dashboard target column** whenever at
  least one listed item has a different source language. When every item is
  sourced in the site default, the column set is unchanged (no regression).
- **Translating into the site default language is allowed** for items not
  sourced in it; the "cannot translate the source language" rejection now keys
  off the item's own source language.
- **Config layouts are read override-free** (`loadOverrideFree()` /
  `loadMultipleOverrideFree()`) everywhere source strings are derived, so an
  existing target-language override cannot bleed into the source.
- No breaking changes: sites where everything is authored in the site default
  language see identical behavior.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities

- `content-translation`: the **Translation dashboard** requirement gains an
  explicit per-item source language and the rule that the site default language
  is itself a target column when any listed item is sourced elsewhere.
- `config-translation`: the **Config layouts in the workspace** requirement
  gains the per-entity source language (including which target langcode is
  rejected), and a new requirement fixes source strings to the override-free
  configuration.

## Impact

- Backend: `src/Controller/ApiTranslateController.php` (`configItems()`,
  `configTranslations()`, the single-config payload, `loadConfigTarget()`),
  `modules/canvas_translate_ai/src/Controller/AiTranslateController.php`
  (source langcode read from the loaded entity, after the access check).
- Frontend (`extension/`): target-column derivation extracted from
  `components/Dashboard.tsx` into a pure `columns.ts` helper so it can be unit
  tested; rebuilt `extension/dist/index.global.js`.
- Tests: `tests/src/Kernel/ConfigTranslateWorkflowTest.php` (non-default source
  langcode; override-free source strings), new `extension/columns.test.ts`.
- The `canvas_translate_ai` controller fix is the same source-language
  correction, covered by the `config-translation` requirement.
