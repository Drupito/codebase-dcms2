## 1. Editor assets endpoint

- [x] 1.1 Add route `canvas_translate.api.editor_assets` (`GET /canvas-translate/api/editor-assets`, `_admin_route`, permission `translate canvas content`) and a controller that resolves `getAttachments()` for the requested CKEditor 5 formats through `asset.resolver`, honours `ajax_page_state[libraries]`, and returns `{settings, js, css, unavailable}`. Verify with a kernel test covering: assets for a CKEditor 5 format, a non-CKEditor format listed as unavailable, already-loaded libraries left out, and access denied without the permission.
- [x] 1.2 Remove `ApiTranslateController::editorFormats()` and the `formats` key from content and config translation responses. Verify the existing kernel tests pass and no PHP references remain (`git grep editorFormats`).

## 2. Loss check

- [x] 2.1 Keep `extension/richtext.ts` (`isLossless`) with its Vitest suite (embed, heading, table, `<s>`, link attributes, class, dropped text, `b`/`i` upcast, paragraph wrapping, whitespace, added attributes). Verify with `npm test`.

## 3. Core editor in the SPA

- [x] 3.1 Add `extension/editorAssets.ts`: request the endpoint with core's `ajax_page_state`, seed or merge `drupalSettings`, append CSS, and load the JS in order. Cache per format set and reject on failure. Verify with type-check, and in the e2e suite (3.4) that a page with two rich rows loads `jquery` once.
- [x] 3.2 Rewrite `extension/components/RichTextField.tsx`: textarea plus `Drupal.editors.ckeditor5.attach/onChange/detach`, readiness through `Drupal.CKEditor5Instances` with a timeout, the loss check before every external value, ignoring changes during programmatic `setData`, and a sticky HTML-textarea fallback with a notice. Remove the `ckeditor5` and `@ckeditor/ckeditor5-react` dependencies and the `EditorFormat`/`formats` types. Verify with `npm run type-check && npm test && npm run build`, and that the dist no longer contains a bundled CKEditor (`grep -c ClassicEditor dist/index.global.js` is 0).
- [x] 3.3 Review the CKEditor framing CSS in `extension/index.html` against core's editor, and keep what still applies. Verify the rich field visually in the browser (toolbar, focus ring, min height).
- [x] 3.4 E2E (`tests/e2e/rich-text.spec.ts`, seeded via `CanvasTranslateTestSetup`):
  - (a) the existing typed rich-text test still passes;
  - (b) the test setup lets the rich prop's format use headings, entity links (`entity_links`) and media (`media_embed`), and a page using them plus a `lang` phrase is edited in CKEditor, keeps that markup through copy source → edit → publish, shows the format's heading button and a live media preview, and opens core's media library dialog;
  - (c) a page with markup the format does not allow (a table) falls back to the HTML textarea and publishes byte for byte;
  - (d) editing an adjacent field leaves an HTML-fallback value unchanged.
  Verify by running the suite, and confirm (b) and (c) fail against the current 1.0.x editor.

## 4. Spec, docs, cleanup

- [x] 4.1 Update `README.md`/`ARCHITECTURE.md` where they describe the bundled editor. Verify with `git grep -i "ckeditor"` across docs.
- [x] 4.2 Run the spec and change validation (`validate --specs`, `validate fix-rich-text-markup-loss`), phpcs/phpstan/cspell as in CI, the kernel tests, Vitest, and the full e2e suite. Verify that all pass.
- [x] 4.3 Cover a format with `drupalMedia` (media embed preview, media library dialog inside the iframe). Verify with e2e (3.4 b) rather than a manual check.
