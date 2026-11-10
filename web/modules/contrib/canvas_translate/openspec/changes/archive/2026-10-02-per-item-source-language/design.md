## Context

See `proposal.md` — Why. Two structural facts shape the approach:

- Content pages already resolve their source per entity
  (`$page->getUntranslated()->language()`), so only the config side and the
  dashboard's column derivation carry the site-default assumption. This is a
  correction toward the existing content behavior, not a new model.
- Config layouts are config entities, so "the translation" is a language
  configuration override on the same object. Loading such an entity through the
  ordinary storage applies the override for the negotiated language — which is
  exactly what must not happen when the value being read is the *source*.

## Goals / Non-Goals

**Goals:**

- One definition of "source language" — the item's own language — used by every
  read path: dashboard listing, per-language status, editor payload, and target
  validation.
- Column derivation that is a pure function of the loaded data, so the two spec
  scenarios (default-language column present / absent) are unit testable without
  rendering the SPA.

**Non-Goals:**

- Changing how translations are stored, published, or diffed.
- Letting an editor choose or change an item's source language; the source is
  whatever the entity was authored in.
- A spec for the `canvas_translate_ai` submodule — `ai-translation` exists only
  as the unapplied `add-ai-translation` change, so the AI controller follows the
  `config-translation` requirement without a delta of its own.

## Decisions

**Source langcode comes from the loaded entity, not the language manager.**
`$entity->language()->getId()` replaces
`$this->languageManager->getDefaultLanguage()->getId()` in `configItems()`,
`configTranslations()`, the single-config payload, and `loadConfigTarget()`.
Alternative considered: keep the site default and special-case items that differ
— rejected because it leaves two notions of source in the codebase and the
status/validation paths would drift apart again.

**Target validation moves after the load.** `loadConfigTarget()` must load the
entity before it can know the source language, so the "cannot translate the
source language" check now runs after the not-found check. The AI controller
gets the same ordering, and there the check also lands after the access check —
so an unauthorized caller cannot distinguish a rejected target from a missing
entity.

**Override-free reads use the storage API, not a language context switch.**
`ConfigEntityStorageInterface::loadOverrideFree()` /
`loadMultipleOverrideFree()` scope the behavior to the calls that need it.
Alternative considered: wrapping reads in an override-free language config
context — rejected as global state that would also affect anything else running
inside the request.

**Column derivation stays client-side, extracted to a pure helper.** The
dashboard already receives `languages` and `items[].sourceLangcode`, so no new
endpoint is needed. Extracting the derivation from `Dashboard.tsx`'s `useMemo`
into `extension/columns.ts` matches how the codebase already isolates logic
(`diff.ts`, `drafts.ts`, each with a colocated Vitest file) and makes both spec
scenarios directly assertable. Alternative considered: having the API return the
target-language set — rejected as a larger API change for behavior the client
can already compute.

## Risks / Trade-offs

- **Columns follow the filtered result set.** Because the derivation reads the
  loaded items, filtering or searching down to only default-sourced items makes
  the site-default column disappear. → Accepted: the column set describing the
  visible rows is the honest reading, and the rail already scopes its
  per-language completion to the current filter the same way.
- **`loadMultipleOverrideFree()` bypasses the override-aware static cache**, so
  the listing may load config entities the request would otherwise have reused.
  → Accepted: the dashboard listing is already an uncached admin request, and
  correctness of the source strings outranks the saving.
- **Existing sites see status change without any edit.** A layout that was
  reported complete only because its override was being read as source will now
  report a real (lower) completeness. → This is the fix, not a regression, but
  it is a visible change on sites that already have config overrides; call it
  out in the issue when the MR lands.
- **Non-`ComponentTreeConfigEntityBase` results are now skipped rather than
  asserted.** Override-free loading returns whatever the storage holds, so a
  hard `assert()` would be a crash surface on an unexpected entity. → Skipping
  is silent; acceptable because the storages listed in `CONFIG_TYPES` only ever
  hold these entities, and an assertion here protected nothing a test covers.
