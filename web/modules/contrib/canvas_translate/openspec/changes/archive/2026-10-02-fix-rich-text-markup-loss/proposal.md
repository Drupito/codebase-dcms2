## Why

Translating a rich-text prop silently deletes markup (issue #3617651). The
extension edits rich text in its own CKEditor build with seven plugins, not in
the editor the text format configures. CKEditor drops every element and
attribute its plugins do not know, so media embeds, headings, tables, and the
`data-entity-*` attributes of managed links disappear on load and are written
back as the translation. This breaks the module's no-data-loss guarantee.

Canvas itself edits the same props with Drupal core's CKEditor 5, configured
by the text format. The translation editor should match it.

## What Changes

- Rich-text target fields use Drupal core's CKEditor 5 with the row's text
  format settings: the same plugins, toolbar, and allowed HTML as in Canvas.
  The extension loads core's editor assets for the formats on the page and
  attaches through core's public editor API.
- A new endpoint returns those assets (settings, JS, CSS) for a set of
  formats, leaving out libraries the extension has already loaded.
- Before a value enters the editor (on load, copy source, or AI translation),
  it is checked against the editor's own data pipeline. If the editor would
  drop anything, the field is edited as HTML in a plain textarea instead, and
  the value is kept byte for byte.
- A format without a CKEditor 5 editor, or one whose assets fail to load, is
  also edited as HTML instead of in a stand-in editor.
- The bundled CKEditor (`ckeditor5`, `@ckeditor/ckeditor5-react`) is removed
  from the extension, along with the `formats` toolbar data in the
  translation responses.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `content-translation`: rich-text props are edited with the text format's own
  editor, and rich-text editing must never destroy markup.

## Impact

- Backend: new `GET /canvas-translate/api/editor-assets` route and controller
  (uses `plugin.manager.editor` and `asset.resolver`).
  `ApiTranslateController::editorFormats()` and the `formats` key in content
  and config translation responses are removed.
- Frontend (`extension/`): `RichTextField.tsx` rewritten around core's editor;
  new editor asset loader; new `richtext.ts` loss check. `ckeditor5` and
  `@ckeditor/ckeditor5-react` dropped, which also shrinks
  `dist/index.global.js` a lot. Rebuilt dist.
- Config translation uses the same field component, so it gets the fix too.
- Tests: kernel coverage for the assets endpoint; Vitest for the loss check;
  Playwright for markup the format allows (kept in the editor) and markup it
  does not (HTML fallback), each through copy source → edit → publish.
- No schema, permission, or Canvas changes.
