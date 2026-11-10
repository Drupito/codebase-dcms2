## Context

Translation drafts are stored in Canvas's langcode-aware auto-save store and
published via this module's own `ApiTranslateController::publish()` — an
unconditional write that, unlike Canvas's diff-based publish, never drops fields
equal to the source (the no-data-loss invariant). Today the only way to publish
is one page + one language at a time, from inside the page editor.

Canvas's content workflow instead autosaves every edit as a draft and ships them
from a single "Review changes → Publish all" tray. We want the same shape for
translations, but **inside the Translate extension** — Canvas core deliberately
filters non-default-translation auto-saves out of its native tray, and its
diff-publish is unsafe for translations, so native-tray integration is blocked
without core changes (work item 3591703).

## Goals / Non-Goals

**Goals:**
- One place to review every pending translation draft across all pages and
  languages, and publish a selected set together.
- Batched publish is all-or-nothing, like Canvas's publish.
- Reuse the existing source-safe per-language publish logic — no new publish
  semantics, no risk to the no-data-loss invariant.

**Non-Goals:**
- Integrating into Canvas's native pending-changes tray (needs core changes).
- Changing config-entity translation, which has no draft layer and publishes
  live on save.
- A new "list pending drafts" backend endpoint (the dashboard data already
  carries draft status per cell).

## Decisions

- **Derive the review list on the client from existing dashboard data.** The
  `items` endpoint already returns `translations[lang].status` (`'draft'`) and
  `percent` per page. The Review view filters those cells; config items never
  carry `draft` status so they're naturally excluded. Avoids a redundant
  endpoint. *Alternative considered:* a dedicated `/pending` endpoint — rejected
  as duplicative for the page counts Canvas sites have today.
- **One new endpoint, `POST /publish-batch`, reusing extracted publish logic.**
  Extract the draft→target application + revision/flag setup from `publish()`
  into a private helper; both single and batch publish call it. Keeps a single
  source of truth for the unconditional-write invariant.
- **Group batch items by page; one revision per page.** Multiple languages of
  one page apply to a single loaded entity and save once, instead of N
  revisions. *Alternative:* publish each (page, langcode) independently —
  rejected as it produces redundant revisions and weakens all-or-nothing.
- **All-or-nothing via a single DB transaction**, mirroring
  `ApiAutoSaveController::post`: validate every page first and return `422` with
  per-item violations before saving anything; wrap saves in one transaction and
  roll back on exception; delete published drafts only after success.
- **Remove the content editor's Publish button.** Publishing moves entirely to
  the Review view, matching Canvas (where the editor never publishes). Discard,
  preview, autosave, and copy-source remain.

## Risks / Trade-offs

- [Client-derived review list can drift from server state if a draft changes
  between dashboard load and publish] → the batch endpoint validates and
  publishes from freshly loaded (`loadUnchanged`) entities and their current
  drafts; a vanished draft for a requested item is reported per-item, not
  silently published.
- [Removing the editor Publish button changes a familiar flow] → the Review view
  is reachable in one click from both the dashboard and the editor's "back"
  path; behavior (source-safety, status) is otherwise identical.
- [Large batches load many pages in one request] → acceptable at current Canvas
  site scale (same assumption the dashboard already makes); revisit with a
  query-level index if sites reach thousands of pending drafts.
