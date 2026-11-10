## Why

Publishing translations today is per-page, per-language: an editor must open
each page's editor, select a language, and click that editor's own Publish
button — one action per page × language. Canvas's own model is the opposite:
edits autosave as drafts and a single global "Review changes → Publish all" tray
ships everything at once. The translation flow should mirror Canvas so editors
can review every pending draft in one place and publish them together.

## What Changes

- Add a **Review & publish** view in the Translate extension that lists every
  pending translation draft across all pages and languages, with per-item
  selection and **Publish selected** / **Publish all**.
- Add a **batch publish** endpoint that publishes the selected drafts in one
  **all-or-nothing** transaction (mirroring Canvas's publish), reusing the
  existing translation-safe (unconditional-write) per-language publish logic.
- Surface a **"Review changes (N)"** entry point on the dashboard, counting
  pending drafts.
- **BREAKING (UX)**: remove the per-page Publish button from the content editor;
  publishing now happens from the Review view. Draft autosave, discard, preview,
  and "copy source" are unchanged. Config-entity translation (no draft layer) is
  unchanged.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `content-translation`: the **Publish translation** requirement gains a
  reviewed, batched publish flow — an editor reviews pending drafts across pages
  and languages and publishes a selected set in one all-or-nothing operation;
  publishing remains source-safe and clears each published draft.

## Impact

- Backend: `src/Controller/ApiTranslateController.php` (extract reusable
  per-language publish helper; add `publishBatch`), `canvas_translate.routing.yml`
  (new `POST /canvas-translate/api/publish-batch` route). No Canvas core changes.
- Frontend (`extension/`): new `components/ReviewPanel.tsx` + `/review` route in
  `App.tsx`; `api.ts` gains `publishBatch`; `Dashboard.tsx` gains the review
  entry point; `PageEditor.tsx` loses the content Publish button. Rebuilt
  `extension/dist/index.global.js`.
- Tests: `tests/src/Kernel/CanvasTranslateWorkflowTest.php` gains batch-publish
  coverage (multi-item success → one revision per page, drafts cleared, source
  untouched; all-or-nothing on validation failure).
- Out of scope: integrating translation drafts into Canvas's native
  pending-changes tray (needs core changes — work item 3591703).
