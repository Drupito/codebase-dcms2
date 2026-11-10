## Why

Editors can't reach a specific page's translations from where they work in
Drupal (issue #3623308). The "Translate" operation and tab on a `canvas_page`
open the full dashboard, so the editor has to find the page again. In listings
that show one row per translation, such as the pages view, "Edit" on a
translation's row opens the Canvas editor on the original, with nothing
indicating that, and there is no way to edit the translation from that row.
Inside the Canvas editor, the language switcher offers to delete a translation
but not to create or edit one, so the editor has to leave the page and find it
again in the dashboard.

## What Changes

- The "Translate" operation and tab (`/page/{id}/translations`, with or without
  a language prefix) open a new **per-page overview** in the extension
  (`/canvas/app/canvas_translate/page/{id}`) instead of the dashboard.
- The per-page overview lists each non-source language of that page with its
  status and completion, and links each language into the side-by-side editor.
- In entity listings, a page translation's row leads with **Edit translation**,
  which opens that language in the side-by-side editor. Core's **Edit** on that
  row is relabeled **Edit original**. Original rows are unchanged.
  "Edit translation" requires the `translate canvas content` permission and
  update access to the page.
- The dashboard items endpoint (`GET /canvas-translate/api/items`) accepts
  `?id=` to return a single page and no config items. This is used by the
  overview.

- In the Canvas editor's language switcher, each language's options offer
  **Edit translation** (existing translations) or **Add translation** (missing
  ones), opening that language in the side-by-side editor. Canvas already
  renders these options when the layout response carries `edit-form` or
  `create` links for a language; this module supplies the links.

Out of scope: a Canvas Translate link in the Canvas sidebar and opening the
editor in the same tab (Canvas opens these links in a new tab). Both need
Canvas changes and are tracked on the issue.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `content-translation`: adds a per-page translation overview that core's
  Translate operation and tab open, and translation-aware row operations
  ("Edit translation", "Edit original") in entity listings, and translation
  links in the Canvas editor's language switcher.

## Impact

- Backend: `TranslateRedirectController` redirects to the page overview;
  new `ExtensionUrl` helper builds deep links into the extension; new
  `Hook/EntityOperationHooks` (`hook_entity_operation` and
  `hook_entity_operation_alter`); `ApiTranslateController::items()` gains the
  `id` filter; new decorator of Canvas's `ApiLayoutController` service adding
  `edit-form` and `create` links to `translations.links` in the layout
  response for `canvas_page`, with an `UPSTREAM(canvas):` marker. No route, permission, or
  schema changes; no Canvas changes.
- Frontend (`extension/`): new `components/PageOverview.tsx` and `/page/:id`
  route in `App.tsx`; `api.ts` `fetchItems()` gains `id`. Rebuilt
  `extension/dist/index.global.js`.
- Tests: kernel coverage in `CanvasTranslateWorkflowTest` (redirect target,
  `?id=` filter, row operations, layout response links, decorator coverage
  of the layout routes); new Playwright spec
  `tests/e2e/entry-points.spec.ts`.
