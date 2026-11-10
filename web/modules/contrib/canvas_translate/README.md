# Canvas Translate

Translate Drupal Canvas content from inside the Canvas editor. It adds a
translation page extension at `/canvas/app/canvas_translate` where you can
translate pages side-by-side with the source, review the changes, and publish —
without a third-party translation service.

## What it does

- **Dashboard** of every Canvas page × language, with a status badge
  (not translated / draft / up to date / outdated) and a completeness
  percentage. Filter by language or status, or search.
- **Side-by-side editor**: read-only source next to an editable target, grouped
  by component. Includes per-component progress, copy-source, rich-text fields
  edited in the text format's own CKEditor 5 (the same editor as Canvas),
  autosave, and an optional live preview pane.
- **Draft → review → publish workflow**. Translations are saved as drafts in the
  module's own store, so Canvas's "publish all" never publishes a half-finished
  translation. You publish from the translation workspace; the source and other
  translations are left untouched.
- **Staleness tracking**. When the translatable source content changes, its
  translations are automatically flagged outdated (core's
  `content_translation_outdated`), and the editor walks you through what changed.
- **Config layouts too**: content templates and page regions are translated
  through the same draft → publish flow, as `config_translation` language
  overrides.

A translation is seeded from the source, and only the values you actually
translate are written — untranslated props fall back to the source. So a
published page always renders fully and required props stay valid. A prop shows
as untranslated when its target still equals the source.

## Requirements

- Drupal Canvas
- `language` and `content_translation` (both core)

## Setup

1. Enable the module:

   ```
   drush en canvas_translate
   ```

2. Turn on content translation for `canvas_page` **and mark its base fields
   translatable**. Canvas ships those base fields non-translatable by default, so
   this step is required — without it, saving a translation overwrites the
   source.

3. Open the editor at `/canvas/app/canvas_translate` (or the "Translate" entry in
   the Canvas sidebar).

For local development, `tests/manual-setup.php` does steps 2 and creates a sample
page in one go:

```
ddev drush scr web/modules/contrib/canvas_translate/tests/manual-setup.php
```

## AI-assisted translation (optional)

The `canvas_translate_ai` submodule adds machine translation through the same
draft path — a bulk "AI translate" action (empty rows by default) and per-row
suggestions in the editor. Results go into the open draft for review; they are
never published directly.

It needs the contrib [`ai`](https://www.drupal.org/project/ai) module with a
configured Translate Text or Chat provider.

```
drush en canvas_translate_ai
```

## Building the extension UI

The editor is a React app bundled with `tsup`. You only need this if you're
changing the UI:

```
cd extension
npm install
npm run verify   # type-check + build
```

## Tests

PHP kernel tests cover the full workflow — status transitions, draft → publish
(source and other translations preserved), outdated flagging, completeness, and
draft cleanup on page delete:

```
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/contrib/canvas_translate/tests/src/Kernel/CanvasTranslateWorkflowTest.php
```

Playwright end-to-end tests live in `tests/e2e/` (`cd tests/e2e && npx playwright test`).

## How it fits together

Canvas Translate reuses Canvas's translatable-string extractor to pull strings
out of a page's component tree, but keeps its drafts in its own store rather than
Canvas's auto-save store. That separation is what lets it sit alongside Canvas's
publish workflow without patching Canvas core. For the deeper design, see
[`ARCHITECTURE.md`](ARCHITECTURE.md).
