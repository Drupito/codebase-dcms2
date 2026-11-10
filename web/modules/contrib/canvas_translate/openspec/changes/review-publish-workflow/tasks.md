## 1. Backend — batch publish

- [x] 1.1 Extract the draft→target application + revision/outdated/source-flag
      setup from `ApiTranslateController::publish()` into a private helper
      (e.g. `applyDraftToStored(Page $stored, string $langcode): ?ContentEntityInterface`),
      and have `publish()` call it (no behavior change).
- [x] 1.2 Inject `Drupal\Core\Database\Connection` into the controller
      (constructor + `create()`).
- [x] 1.3 Add `publishBatch(Request)`: parse `{items:[{id,langcode}]}`, group by
      page id, load each page `loadUnchanged`, access-check `update`, apply each
      requested langcode's draft, validate all pages; on any violation return
      `422` with per-item details and save nothing; otherwise save all pages in
      one DB transaction (rollback on exception), then delete published drafts;
      return per-item resulting status.
- [x] 1.4 Add route `canvas_translate.api.publish_batch`
      (`POST /canvas-translate/api/publish-batch`, `_permission: 'translate
      canvas content'`, `no_cache: TRUE`) in `canvas_translate.routing.yml`.
- [x] 1.5 `php -l` the changed PHP files.

## 2. Frontend — review workspace

- [x] 2.1 `api.ts`: add `publishBatch(items: {id:number; langcode:string}[])`
      (POST + CSRF, parse per-item result/errors).
- [x] 2.2 New `components/ReviewPanel.tsx` (route `/review`): from `fetchItems()`,
      flatten content items to one row per `status === 'draft'` cell; per-row
      checkbox (default checked), Open (→ editor), Discard draft; Publish
      selected / Publish all; empty state; surface per-item errors inline.
- [x] 2.3 `App.tsx`: register the `/review` route.
- [x] 2.4 `Dashboard.tsx`: add a "Review changes (N)" entry point → `/review`,
      N = count of `draft` cells in the loaded data.
- [x] 2.5 `PageEditor.tsx`: remove the content Publish button (keep autosave,
      discard, preview, copy-source); config editor unchanged.
- [x] 2.6 `cd extension && npm run type-check && npm run build`; commit
      `extension/dist/index.global.js`.

## 3. Tests & docs

- [x] 3.1 `tests/src/Kernel/CanvasTranslateWorkflowTest.php`: add `testBatchPublish()`
      — multi-item success (drafts published with their values, drafts cleared,
      source + untargeted languages unchanged; two langcodes of one page → one
      new revision).
- [x] 3.2 Add an all-or-nothing case: one invalid draft → nothing saved, all
      drafts remain.
- [x] 3.3 Update `AGENTS.md` to note the `publish-batch` endpoint alongside the
      publish invariant.
- [x] 3.4 Run kernel tests; they pass.

## 4. Verify & archive

- [x] 4.1 `npx -y @fission-ai/openspec@latest validate --specs` passes.
- [ ] 4.2 Manual smoke on live ddev: draft on 2+ pages/languages, confirm the
      review count, publish selected, confirm dashboard + rendered pages.
- [ ] 4.3 After implementation lands, archive the change
      (`/opsx:archive` / `openspec archive`) so `openspec/specs/` reflects the
      new behavior.
