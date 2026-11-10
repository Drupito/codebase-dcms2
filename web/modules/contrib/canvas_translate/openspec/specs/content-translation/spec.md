# content-translation

## Purpose

Let editors translate Canvas page content (the `canvas_page` content entity)
in-house — without a third-party translation service — through a dedicated
workspace, with a creation-and-editing workflow that mirrors Canvas's own
draft-then-publish model and never loses source content.

## Requirements

### Requirement: Translation workspace access

The module MUST expose its translation workspace as a Canvas page extension,
restricted to users holding the `translate canvas content` permission, and all
per-page mutations MUST additionally require entity update access to that page.

#### Scenario: Authorized editor opens the workspace

- **WHEN** a user with the `translate canvas content` permission opens the Canvas Translate extension
- **THEN** the system MUST render the translation dashboard

#### Scenario: Unauthorized user is denied

- **WHEN** a user without the `translate canvas content` permission requests any Canvas Translate endpoint
- **THEN** the system MUST deny access

#### Scenario: Reading a translation requires page update access

- **WHEN** a user with the workspace permission but without update access to a page requests its side-by-side fields
- **THEN** the request MUST be denied (403)

### Requirement: Translation dashboard

The system MUST list every translatable page the editor can update, showing,
for each configured non-source language, the translation state and a link into
the editor. A page's source language is the language that page was authored in,
which is not necessarily the site default language. The dashboard MUST
therefore offer the site default language as a target column whenever at least
one listed item has a different source language, and MUST NOT offer it when
every listed item is sourced in it. The dashboard MUST support filtering by
language and status and searching by page label.

#### Scenario: Listing pages with per-language state

- **WHEN** the editor opens the dashboard
- **THEN** the system MUST list each updatable page with one column per non-source language
- **AND** each cell MUST show that language's translation status

#### Scenario: An item is sourced in a non-default language

- **WHEN** the dashboard lists an item whose source language differs from the site default language
- **THEN** the site default language MUST be offered as a target column
- **AND** that item's cell in that column MUST show its translation status into the site default language

#### Scenario: Every item is sourced in the site default language

- **WHEN** every item the dashboard lists is sourced in the site default language
- **THEN** the site default language MUST NOT be offered as a target column

#### Scenario: The source language cell is not a translation target

- **WHEN** the dashboard shows an item's cell in that item's own source language
- **THEN** the cell MUST be labeled as the source language
- **AND** it MUST NOT show a translation status, completeness, or an action that opens the editor

#### Scenario: Filtering

- **WHEN** the editor filters by a language and a status
- **THEN** the system MUST list only pages whose translation in that language has that status

### Requirement: Side-by-side editor

The system MUST present, per page and target language, the translatable strings
grouped by component, each as a read-only source value beside an editable target
value. Plain-text props MUST use a text field; rich-text props (those carrying a
text format) MUST use the editor that text format configures, with the same
features and allowed markup as in Canvas.

#### Scenario: Opening a page for a target language

- **WHEN** the editor opens a page for a target language
- **THEN** the system MUST list every translatable string with its source value and current target value, grouped by component

#### Scenario: Rich text uses the format's editor

- **WHEN** a rich-text prop's text format uses CKEditor 5
- **THEN** its target value MUST be edited in CKEditor 5 configured by that format
- **AND** markup the format allows (such as headings, embedded media, or a managed link's `data-entity-*` attributes) MUST be kept in the editor

### Requirement: Draft autosave

While editing, the system MUST persist target edits as a language-scoped draft
without publishing them, so work is not lost and is not visible to readers until
published.

#### Scenario: Editing a field

- **WHEN** the editor changes a target value
- **THEN** the system MUST save the change to a draft keyed by the page and target language
- **AND** the published page MUST be unchanged until the draft is published

### Requirement: Source fallback for untranslated content

The system MUST preserve the source value for every prop a translator has not
translated. Saving or publishing MUST never write an empty value over a prop
that has source content.

#### Scenario: Partial translation publishes without loss

- **WHEN** a translator translates some props, leaves others untranslated, and publishes
- **THEN** the untranslated props MUST retain their source value in the published translation
- **AND** publishing MUST NOT fail validation for an emptied required prop

#### Scenario: Untranslated props are surfaced as untranslated

- **WHEN** a prop's stored target value equals its source value
- **THEN** the editor MUST present that prop as untranslated (empty target)

### Requirement: Copy source

The system MUST let the editor copy a source value into the corresponding target
field, individually or for all currently-empty fields at once.

#### Scenario: Copy source then publish

- **WHEN** the editor copies source values into target fields and publishes
- **THEN** the published translation MUST contain those values
- **AND** the translation MUST NOT publish empty

### Requirement: Never write a non-translatable page field

The module MUST never write a non-translatable page field.

Publishing writes the target text onto the `title`, `description` and
`components` fields of the target translation. A write to a non-translatable
field lands on field values shared with the source language and overwrites the
original page (Canvas ships these base fields with `translatable: false`
overrides), so the module MUST never write a non-translatable field, at two
tiers:

- **Blocked** — translation cannot work at all: content translation is not
  enabled for the `canvas_page` bundle, or no written field is translatable.
  Loading the side-by-side editor, saving a draft, and publishing (single or
  batch) MUST fail with `409 Conflict` and a machine-readable `code` of
  `translation_not_enabled`, without modifying the page. The dashboard payload
  MUST carry `translationBlocked: true`, and the UI MUST hide the entire
  translation workspace behind setup instructions (what to enable, linking to
  the Content language settings page — without prescribing which fields to
  select) rather than a bare error string. The status report MUST surface this
  via a runtime requirement, as an error when the site is multilingual.
- **Excluded fields** — an individual field being non-translatable while
  others remain translatable is normal site configuration, not an error.
  Such fields MUST be excluded everywhere — editor rows, saved draft values,
  publish writes, completeness math, outdated tracking — while the rest of
  the workspace keeps working, and MUST NOT trigger any warning or notice.

#### Scenario: Workspace hidden when translation is not enabled

- **GIVEN** content translation is disabled for the `canvas_page` bundle (or
  no written field is translatable)
- **WHEN** the translator opens any translation surface or publishes a
  pending draft
- **THEN** the request MUST fail with `409 Conflict` and code `translation_not_enabled`
- **AND** the source page MUST be unchanged and any pending draft preserved
- **AND** the UI MUST show only setup instructions in place of the workspace

#### Scenario: A single non-translatable field is excluded silently

- **GIVEN** the `description` base field is not translatable while `title` and
  `components` are
- **WHEN** the translator opens the editor, drafts values (including one for
  `description`), and publishes
- **THEN** the editor MUST omit the `description` row without any warning
- **AND** publish MUST write the other fields and MUST NOT touch
  `description`'s (source-shared) storage

### Requirement: Publish translation

The system MUST publish a draft by writing the translated values onto the target
translation as a new revision, leaving the source translation and every other
translation unchanged, and MUST then clear the draft.

#### Scenario: Publishing a translation

- **WHEN** the editor publishes a target-language draft
- **THEN** the system MUST create a new revision carrying the translated values for that language
- **AND** the source and all other translations MUST be unchanged
- **AND** the draft MUST be cleared

### Requirement: Discard draft

The system MUST let the editor discard an in-progress draft, reverting the
target language to its last published state.

#### Scenario: Discarding a draft

- **WHEN** the editor discards a draft
- **THEN** the system MUST remove the draft and report the resulting published status

### Requirement: Draft cleanup on page deletion

When a page is deleted, the system MUST remove every language-scoped draft for
that page, including drafts for languages that were never published.

#### Scenario: Deleting a page with an open draft

- **WHEN** a page that has an unpublished translation draft is deleted
- **THEN** the system MUST delete that draft so no orphaned draft remains

### Requirement: Draft approval (review staging)

The system MUST support a review-staging step between drafting and publishing: a
reviewer can mark a pending draft approved, making that flag visible to the
dashboard and the editor. Approval is always scoped to the exact draft that was
reviewed and MUST be cleared automatically whenever that draft changes, is
discarded, or is published.

#### Scenario: Approving a pending draft

- **WHEN** a reviewer approves a pending draft
- **THEN** the system MUST set the draft's approved flag to TRUE
- **AND** the flag MUST be surfaced on the editor document and on the dashboard cell

#### Scenario: Approving with no pending draft is rejected

- **WHEN** approve is called and no draft exists for that page + language
- **THEN** the system MUST return 400 Bad Request

#### Scenario: Editing the draft clears the approval

- **WHEN** a draft is edited (autosaved) after it was approved
- **THEN** the approved flag MUST be cleared so the updated draft must be reviewed again

#### Scenario: Discarding the draft clears the approval

- **WHEN** an approved draft is discarded
- **THEN** the approved flag MUST be cleared together with the draft

#### Scenario: Publishing the draft clears the approval

- **WHEN** an approved draft is published
- **THEN** the approved flag MUST be cleared; the published document reports not-approved

#### Scenario: Unapprove withdraws a prior approval

- **WHEN** a reviewer calls unapprove on an approved draft
- **THEN** the system MUST clear the approved flag and return approved: FALSE

#### Scenario: Unapprove is a no-op when not approved

- **WHEN** unapprove is called and no approval is set
- **THEN** the system MUST return approved: FALSE without error

#### Scenario: Approve and unapprove require page update access

- **WHEN** a user without page update access calls approve or unapprove
- **THEN** the system MUST deny access (403)

### Requirement: Mark reviewed without rewrite

The system MUST let a translator mark an outdated translation reviewed —
accepting the existing values as still valid for the changed source — without
rewriting any values. Marking reviewed MUST clear the outdated flag and cut a
new revision so that future source changes are measured from this point.

#### Scenario: Mark reviewed clears the outdated flag

- **WHEN** a reviewer marks a published but outdated translation as reviewed
- **THEN** the system MUST set the outdated flag to FALSE and create a new revision
- **AND** every translated value MUST remain exactly as it was before

### Requirement: Review diff exposes pending changes

While a draft is pending, the system MUST expose, per changed field, the
currently-published target value (`targetWas`) so that a reviewer can diff what
will change on publish. The system MUST also expose, per changed source field
when the translation is outdated, the source value as it was at the last publish
(`sourceWas`), derived live from revision history.

#### Scenario: targetWas for a brand-new draft

- **WHEN** a draft is saved for a language that has no published translation
- **THEN** every field in the draft MUST carry targetWas: '' (all-added)

#### Scenario: targetWas for an updated draft

- **WHEN** a draft changes only some fields of an existing published translation
- **THEN** each changed field MUST carry the prior published value as targetWas
- **AND** unchanged fields MUST carry no targetWas

#### Scenario: sourceWas for changed source fields

- **WHEN** the translation is outdated and the editor opens it
- **THEN** each field whose source changed since the last publish MUST carry the prior source text as sourceWas
- **AND** fields whose source is unchanged MUST carry no sourceWas

### Requirement: Per-page translation overview

The system MUST offer an overview of a single page listing each of its
non-source languages with that language's translation status and a link into
the editor. Core's translation overview for a `canvas_page` (the "Translate"
operation and tab) MUST open this overview for that page, not the dashboard.

#### Scenario: Translate opens the page's overview

- **WHEN** the editor follows the "Translate" operation or tab of a page
- **THEN** the system MUST open the Canvas Translate overview of that page
- **AND** the overview MUST list each non-source language with its translation status

#### Scenario: Opening a language from the overview

- **WHEN** the editor selects a language on the page's overview
- **THEN** the system MUST open the side-by-side editor for that page and language

#### Scenario: Overview data is limited to the page

- **WHEN** the dashboard data is requested for a single page id
- **THEN** the system MUST return only that page, and no config items

### Requirement: Translation row operations

In entity listings that show one row per translation, the operations of a
page translation's row MUST lead with "Edit translation", opening the
side-by-side editor for that page and language, for users with the
`translate canvas content` permission and update access to the page. Core's
"Edit" operation on that row MUST be labelled "Edit original", since the
Canvas editor always opens the source. The original's row MUST be unchanged.

#### Scenario: Operations on a translation's row

- **WHEN** the editor opens the operations of a page translation's row
- **THEN** the first operation MUST be "Edit translation" into that language's editor
- **AND** core's "Edit" MUST be labelled "Edit original"

#### Scenario: Operations on the original's row

- **WHEN** the editor opens the operations of a page's original row
- **THEN** the system MUST NOT add "Edit translation" or relabel "Edit"

#### Scenario: Without the workspace permission (row operations)

- **WHEN** a user without the `translate canvas content` permission opens the operations of a page translation's row
- **THEN** the system MUST NOT offer "Edit translation"
- **AND** core's "Edit" MUST still be labelled "Edit original"

### Requirement: Language switcher translation links

In the Canvas editor's language switcher for a page, each configured language
other than the page's source language MUST offer "Edit translation" when the
page has that translation and "Add translation" when it does not, each opening
the side-by-side editor for that page and language. The system MUST offer
these links only to users with the `translate canvas content` permission and
update access to the page, and MUST keep the switcher's other options, such as
"Delete translation", unchanged.

#### Scenario: Existing translation

- **WHEN** the editor opens the options of a language the page is translated into
- **THEN** the system MUST offer "Edit translation" into that language's side-by-side editor
- **AND** "Delete translation" MUST still be offered where Canvas offers it

#### Scenario: Missing translation

- **WHEN** the editor opens the options of a language the page is not translated into
- **THEN** the system MUST offer "Add translation" into that language's side-by-side editor

#### Scenario: Source language

- **WHEN** the editor opens the options of the page's source language
- **THEN** the system MUST NOT offer "Edit translation" or "Add translation"

#### Scenario: Without the workspace permission (language switcher)

- **WHEN** a user without the `translate canvas content` permission opens the language switcher
- **THEN** the system MUST NOT offer "Edit translation" or "Add translation"

### Requirement: Rich text without an editor is edited as HTML

When a rich-text prop's format has no usable CKEditor 5 editor, the translation
editor MUST let the value be edited as HTML instead of in a substitute editor.
Markup the format's editor does not allow is handled as Canvas handles it.

#### Scenario: No editor available for the format

- **WHEN** a rich-text prop's format has no CKEditor 5 editor, or the editor fails to load
- **THEN** the field MUST be edited as HTML, with a notice explaining why
- **AND** publishing MUST write exactly the HTML the translator left

### Requirement: Rich text follows core's text format rules

The translation editor MUST apply the same text format rules as core's own
forms: a rich-text value MUST reach the editor only after core's text editor
XSS filter, and a rich-text field in a format the user may not use MUST be
read-only.

#### Scenario: Value with unsafe markup

- **WHEN** a rich-text source or target value contains markup core's text editor XSS filter removes, such as an event handler attribute or a `javascript:` URL
- **THEN** the editor MUST receive and display the filtered value

#### Scenario: Format the user may not use

- **WHEN** a rich-text field's format is one the user may not use
- **THEN** the field MUST be shown read-only, with a notice explaining why
- **AND** the format's editor MUST NOT be served to that user
- **AND** saving the translation MUST leave that field's value unchanged
