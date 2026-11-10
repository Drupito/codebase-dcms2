## MODIFIED Requirements

### Requirement: Config translation storage via language overrides

The system MUST store *published* config-layout translations as configuration
language overrides for the target language, leaving the active (source)
configuration unchanged. In-progress edits MUST be staged as a workspace-owned
draft and MUST NOT alter the live language override until the translation is
published.

#### Scenario: Saving stages a draft

- **WHEN** the editor saves a translated string for a config layout in a target language
- **THEN** the system MUST stage that string as a workspace-owned draft
- **AND** the live language override and the active source configuration MUST be unchanged

#### Scenario: Publishing writes the live override

- **WHEN** the editor publishes a config layout's target-language draft
- **THEN** the system MUST write the staged values into the target-language configuration override
- **AND** the draft MUST be cleared

#### Scenario: Reading an in-progress or published config translation

- **WHEN** the editor opens a config layout that has a target-language draft or override
- **THEN** the editor MUST show the draft values when a draft exists, otherwise the published override values, as the current target values

## ADDED Requirements

### Requirement: Config translation drafts are private to the workspace

Config translation drafts MUST be held in storage owned by the translation
workspace, such that Canvas's config publish workflow neither lists them as pending
changes nor publishes them when the base config entity is published.

#### Scenario: Publishing a config layout does not publish its translation draft

- **WHEN** an editor publishes a content template or page region through the Canvas editor's publish workflow
- **AND** that layout has a pending translation draft in another language
- **THEN** the translation draft MUST remain unpublished and intact
- **AND** the live language override for that language MUST be unchanged
