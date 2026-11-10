## ADDED Requirements

### Requirement: Translation drafts are private to the workspace

Translation drafts MUST be held in storage owned by the translation workspace,
such that Canvas's content publish workflow neither lists them as pending changes
nor publishes them. A translation goes live only when published from the
translation workspace.

#### Scenario: Publishing source content does not publish a translation draft

- **WHEN** an editor publishes a page's default-language content through the Canvas editor's publish workflow
- **AND** that page has a pending translation draft in another language
- **THEN** the translation draft MUST remain unpublished and intact
- **AND** the published page's other-language translation MUST be unchanged

#### Scenario: Translation drafts are absent from Canvas's pending changes

- **WHEN** a translation draft exists for a page
- **THEN** that draft MUST NOT appear in Canvas's list of pending auto-saved changes

### Requirement: Publish preserves concurrent structural changes

Publishing a translation MUST apply the draft's translated values onto the current
stored translation, so that a structural change (e.g. a component version update)
published after the draft was saved is preserved rather than overwritten by stale
structure carried in the draft.

#### Scenario: Publishing after a structural change preserves structure

- **WHEN** a component version update has been published to a page after a translation draft was saved
- **AND** the editor then publishes that translation draft
- **THEN** the published translation MUST carry the updated component structure
- **AND** the published translation MUST carry the draft's translated values
