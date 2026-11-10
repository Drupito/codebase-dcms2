## MODIFIED Requirements

### Requirement: Publish translation

The system MUST publish a draft by writing the translated values onto the target
translation as a new revision, leaving the source translation and every other
translation unchanged, and MUST then clear the draft. The system MUST be able to
publish a single draft or, in one operation, a set of drafts selected from the
review workspace.

#### Scenario: Publishing a translation

- **WHEN** the editor publishes a target-language draft
- **THEN** the system MUST create a new revision carrying the translated values for that language
- **AND** the source and all other translations MUST be unchanged
- **AND** the draft MUST be cleared

## ADDED Requirements

### Requirement: Review and batch publish translations

The system MUST provide a review workspace that lists every pending translation
draft across all translatable pages and languages the editor can update, and
MUST let the editor publish a selected set of those drafts in one operation. The
batched publish MUST be all-or-nothing: if any selected draft fails validation,
the system MUST publish none of them and leave every draft intact.

#### Scenario: Reviewing pending drafts

- **WHEN** the editor opens the review workspace
- **THEN** the system MUST list each page-and-language that has a pending draft
- **AND** items without a pending draft MUST NOT appear

#### Scenario: Publishing a selected set of drafts

- **WHEN** the editor selects multiple pending drafts and publishes them
- **THEN** the system MUST publish each selected draft as described in "Publish translation"
- **AND** each published draft MUST be cleared
- **AND** unselected drafts, the source, and every other translation MUST be unchanged

#### Scenario: Multiple languages of one page publish as one revision

- **WHEN** the editor publishes drafts for two languages of the same page in one operation
- **THEN** the system MUST apply both translations to that page and create a single new revision

#### Scenario: Batch publish is all-or-nothing

- **WHEN** a batch includes one draft that fails validation
- **THEN** the system MUST publish none of the drafts in that batch
- **AND** every draft in the batch MUST remain unpublished and intact
