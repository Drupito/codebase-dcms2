## Context

The extension is a static SPA (`extension/index.html`) that Canvas loads in a
same-origin iframe (`sandbox="allow-scripts allow-same-origin
allow-downloads"`). The iframe is not a Drupal-rendered page, so it has no
`Drupal`, `drupalSettings`, or core libraries.

`RichTextField.tsx` builds its own `ClassicEditor` from the npm `ckeditor5`
package with seven plugins and no General HTML Support (GHS).
`ApiTranslateController::editorFormats()` sends only the format's toolbar item
names and the list `multiBlock` flag, so toolbar items without a plugin are
hidden and markup without a plugin is dropped.

Canvas edits the same props in `DrupalFormattedTextArea.tsx` using core's
CKEditor 5 globals (`window.CKEditor5`) and the format's `editorSettings`
(plugins, toolbar, and processed config, including a GHS allow-list derived
from the format's allowed HTML). Core exposes the same thing to any page
through `plugin.manager.editor::getAttachments()` (libraries plus
`drupalSettings.editor.formats`) and the public JS editor API
`Drupal.editors.ckeditor5.{attach,detach,onChange}`.

A spike confirmed this works in a bare page: loading the `full_html`
attachments resolved by `asset.resolver` into a static HTML page, then calling
`Drupal.editors.ckeditor5.attach()` on a textarea, gave a working editor with
the format's 21 toolbar items. It kept `<h2>`, `<s>`, a table (adding
`class="table"`), `<drupal-media>`, and a link's `data-entity-*` and `target`
attributes byte for byte, with no console errors.

## Goals / Non-Goals

**Goals:**
- Same editor, plugins, toolbar, and allowed markup as Canvas for each format.
- Never write a value the translator did not author.
- No copy of core's or Canvas's editor bootstrap code.

**Non-Goals:**
- Changing who may edit which text format. Today any translator edits any
  format's rich text; that is unchanged here and tracked separately.
- A Canvas extension API for attaching Drupal libraries. This change uses core
  APIs directly; such an API would only simplify the loader.
- Rendering the read-only source column differently.

## Decisions

### Core's CKEditor 5 through `Drupal.editors.ckeditor5`, not a bundled build

Each rich-text row renders a textarea, and the field attaches core's editor
with `Drupal.editors.ckeditor5.attach(textarea, format)`. It listens with
`onChange()` and reads the data from `Drupal.CKEditor5Instances`. On unmount it
calls `detach(textarea, format, 'unload')`. This is the same path every
Drupal form uses, so behavior, plugins (`drupalMedia`, `drupalLink`, image,
table, source editing), dialogs, and translations of the UI match Canvas and
core exactly, and stay in step with core updates.

Alternatives considered:
- **Add GHS to the bundled build** (configured from the format's allowed HTML).
  This narrows the loss but can't load Drupal-specific plugins (`drupalMedia`
  has no npm package), keeps a second CKEditor version in step with core's by
  hand, and still differs from Canvas.
- **Copy Canvas's `DrupalFormattedTextArea` / core's `processConfig`.** Works,
  but forks code (core issue #3521761 is the upstream fix Canvas is waiting on)
  and breaks the "no forking" governance rule. Core's `attach()` is public and
  does the same.
- **Use the parent window's `CKEditor5` globals.** This depends on whichever
  libraries Canvas happened to load, and couples to Canvas internals across
  realms. Rejected.
- **Server-side allowlist of representable tags** (the patch on the issue).
  It duplicates the plugin list in PHP and reports the row's format as `NULL`
  to force a textarea, which the review diff then word-diffs as plain text,
  corrupting tags. Rejected.

### Assets from a dedicated endpoint, using core's already-loaded mechanism

`GET /canvas-translate/api/editor-assets?formats=a,b` (permission
`translate canvas content`) builds `AttachedAssets` from
`getAttachments($formats)` and returns:

- the `drupalSettings` from `asset.resolver`, including `editor.formats`,
  `path`, and an updated `ajaxPageState`;
- the ordered JS and CSS URLs, aggregated per core's performance settings.

Requests carry core's standard `ajax_page_state[libraries]` and
`ajax_page_state[theme]` parameters. Core's `AjaxPageState` middleware
decompresses them, and the controller passes them to
`setAlreadyLoadedLibraries()`, so a later page with a new format loads only
the missing libraries. jQuery and `drupal.js` are never loaded twice.

Only formats whose editor is `ckeditor5` are included. A missing or other
editor is reported so the field falls back to HTML.

The translation responses drop `formats`. The SPA asks the endpoint for the
formats of the rows it shows, so the doc payload stays editor-agnostic.

Alternative: embed the assets in the translation responses. Rejected: it
couples every doc fetch (also used by tests and AI) to the client's asset
state.

### Loader in the SPA

`editorAssets.ts` loads each format set once:
1. It merges the returned settings into `window.drupalSettings`. On the first
   load it creates the `data-drupal-selector="drupal-settings-json"` element
   that `drupalSettingsLoader.js` reads.
2. It appends CSS links and then the JS scripts strictly in order, awaiting
   each script's `load` event.

A failed load rejects the promise and the affected fields fall back to HTML.
Loading is lazy: pages without rich text load nothing.

### Loss check against the attached editor's data pipeline

The check works on a value before it enters the editor. The textarea is
attached empty. Once the instance exists, the value is round-tripped
(`editor.data.stringify(editor.data.parse(value))`) and compared with
`isLossless()` in `richtext.ts`. That function compares a multiset of
elements and `element[attr=value]` features, plus the text with whitespace
ignored. It allows additions and the rendering-neutral rewrites (`b`→`strong`,
`i`→`em`, `<p>` wrapping).

Only a lossless value is passed to `setData`. Otherwise the field detaches and
switches to the HTML textarea with a notice, and stays in that mode for the
component's lifetime, so editing HTML never swaps widgets mid-keystroke.
External value changes (copy source, AI) go through the same check. Changes
coming from the editor's own `onChange` are not re-checked.

Because the check uses the real editor, it has no allow-list and stays
correct for any format, plugin set, or core update. With the format's own
editor it should fire only for markup the format itself does not allow.

Alternative: rely on the format's editor alone. Rejected: content can predate
a format change, or come from migrations, the API, or AI output. The module's
no-data-loss guarantee should not depend on that.

### Programmatic `setData` is not an edit

Loading or replacing a value fires `change:data`. The field ignores changes
while it is applying a value, so loading a page never marks a row as edited or
rewrites a stored value with its normalized form.

## Risks / Trade-offs

- **First load is heavier** (core JS, jQuery UI for dialogs, CKEditor DLL).
  Mitigation: the assets are aggregated, browser-cached, loaded only when rich
  rows exist, and partly offset by dropping the ~1 MB bundled CKEditor.
- **Theme-dependent assets** (`libraries-extend`, editor stylesheets). The
  endpoint is an admin route, so assets resolve against the admin theme, as
  in Canvas's own forms.
- **Core's `attach()` returns nothing and logs errors to the console.**
  Mitigation: readiness is detected through `Drupal.CKEditor5Instances` with a
  timeout. A timeout is treated as a load failure and the field falls back to
  HTML.
- **Global `Drupal`/`jQuery` in the iframe.** The SPA does not use them, and
  React owns only its own nodes. Core's editor UI lives under the textarea's
  parent, which React leaves alone (it is rendered without children).
- **Dialog-based plugins** (media library, link dialogs) open jQuery UI
  dialogs inside the iframe. Covered by e2e on a format with `drupalMedia`.

## Migration Plan

No data or config migration. The dist bundle changes and `formats` leaves the
translation responses. That key is internal to the SPA, so nothing else
consumes it. Rollback is reverting the commit.
