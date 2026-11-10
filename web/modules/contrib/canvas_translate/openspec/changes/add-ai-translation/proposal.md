# Add optional AI machine translation

## Why

Translators fill the side-by-side editor's target fields by hand. For sites that
have the [Drupal AI](https://www.drupal.org/project/ai) module, we can pre-fill
those fields with machine translation, leaving the human to review and publish.

The dependency on AI MUST be optional: a site that does not want AI keeps a clean
`canvas_translate` with no `ai` coupling in its service container. We therefore
ship the feature as a submodule (`canvas_translate_ai`) that hard-depends on `ai`;
enabling the submodule is the opt-in.

AI output is never authoritative — it lands in the existing language-scoped draft
for human review before publish, so the no-data-loss / draft-then-publish model is
unchanged.

## What Changes

- **New capability `ai-translation`** (new submodule `modules/canvas_translate_ai/`):
  - A backend that translates a set of `{key, format, source}` strings to a target
    language via Drupal AI's `translate_text` operation and returns suggested target
    values. It access-checks the entity but performs no field extraction and writes
    no content — the SPA supplies the source strings and applies the suggestions to
    the existing draft.
  - Endpoints for both content pages and config entities (content templates, page
    regions), mirroring the existing translate routes.
  - Bulk "AI translate" and per-row AI controls in the editor. Bulk defaults to
    untranslated rows, with a "re-translate all" option.
- The side-by-side editor responses advertise whether AI assistance is available
  (governed by the new `ai-translation` capability), so the SPA shows AI controls
  only when the submodule is enabled. This is the only change to the parent module
  and adds no `ai` dependency (it checks module presence, not the `ai` service).

## Prerequisite (operator)

Drupal AI's `translate_text` operation requires a provider that implements it (e.g.
DeepL) set as the default for that operation at `admin/config/ai/settings`. The
installed OpenAI provider does **not** implement `translate_text`. When no default
`translate_text` provider is configured, AI endpoints fail cleanly and the editor
surfaces the reason.

## Impact

- Affected specs: `ai-translation` (added).
- Affected code: new `modules/canvas_translate_ai/`; one field added to two JSON
  responses in `src/Controller/ApiTranslateController.php`; SPA additions in
  `extension/` (`types.ts`, `api.ts`, `components/PageEditor.tsx`,
  `components/FieldRow.tsx`).
- No change to the draft, publish, source-fallback, or status behavior.
