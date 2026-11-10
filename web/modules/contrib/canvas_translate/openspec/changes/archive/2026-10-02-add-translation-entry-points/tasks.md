## 1. Backend

- [x] 1.1 Add `src/ExtensionUrl.php` with `forPage($id, ?$langcode)` building
      `base:canvas/app/canvas_translate/page/{id}[/{langcode}]`.
- [x] 1.2 `TranslateRedirectController::overview()` takes the `canvas_page`
      route parameter and redirects to `ExtensionUrl::forPage($id)`.
- [x] 1.3 `ApiTranslateController::items()`: accept `?id=`, add an entity
      query condition on `id`, and skip config items when it is set.
- [x] 1.4 Add `src/Hook/EntityOperationHooks.php`: "Edit translation"
      (weight 0) on page translation rows for users with
      `translate canvas content` and update access; relabel core's "Edit" to
      "Edit original" on page translation rows.

- [x] 1.5 Add `src/Controller/ApiLayoutControllerDecorator.php` decorating
      `Drupal\canvas\Controller\ApiLayoutController` in
      `canvas_translate.services.yml`. `get()`: for a `canvas_page` and users
      with `translate canvas content` and update access, add `edit-form`
      (translated languages) or `create` (missing ones) to
      `translations.links[langcode]` for every non-source language, pointing
      to `ExtensionUrl::forPage($id, $langcode)`; keep existing keys; return a
      new `PreviewEnvelope`. Pass `patch()`, `post()`, `getLabel()`, and
      `draftContentTemplate()` through. Add an `UPSTREAM(canvas):` marker
      naming the upstream link emission that replaces it.

## 2. Frontend

- [x] 2.1 `api.ts`: `fetchItems()` accepts `id` and passes it as `?id=`.
- [x] 2.2 New `components/PageOverview.tsx`: page heading, one row per
      non-source language with status and completion, each linking to
      `/page/:id/:langcode`.
- [x] 2.3 `App.tsx`: register the `/page/:id` route.
- [x] 2.4 `cd extension && npm run type-check && npm run build`; commit
      `extension/dist/index.global.js`.

## 3. Tests

- [x] 3.1 Kernel (`CanvasTranslateWorkflowTest`): the Translate route
      redirects to the page overview URL.
- [x] 3.2 Kernel: `?id=` returns only that page and no config items.
- [x] 3.3 Kernel: row operations on translation and original rows, with and
      without `translate canvas content`.
- [x] 3.4 Kernel: the layout response for a page carries `edit-form` for an
      existing translation, `create` for a missing language, nothing for the
      source language, keeps `delete-form`, and adds nothing without
      `translate canvas content`.
- [x] 3.5 Kernel: the decorator has every `ApiLayoutController` method a
      route's `_controller` references.
- [x] 3.6 E2E (`tests/e2e/entry-points.spec.ts`): Translate opens the page
      overview, and a language row opens its editor.
- [x] 3.7 E2E: in the Canvas editor, the language switcher's
      "Add translation" for a missing language opens that language's editor
      (in the new tab Canvas opens).

## 4. Specs

- [x] 4.1 `npx -y @fission-ai/openspec@latest validate --specs` passes.
- [x] 4.2 Archive this change to sync the requirements into
      `openspec/specs/content-translation/spec.md`.
