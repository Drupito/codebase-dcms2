# translation-preview

## Purpose

Let translators see their translation rendered in context — the actual page in
the target language, reflecting unpublished draft edits — before publishing.

## Requirements

### Requirement: Live target-language preview

The system MUST provide an optional preview of a page in a target language that
renders the page as it would appear, applying the in-progress draft, and MUST
refresh as the draft changes.

#### Scenario: Toggling the preview

- **WHEN** the editor enables the preview for a page being translated
- **THEN** the system MUST render the page in the target language with the current draft applied

#### Scenario: Draft edits reflected

- **WHEN** the editor changes a translated component string and it autosaves
- **THEN** the preview MUST refresh to show the updated string

### Requirement: Preview chrome and scope

The preview MUST render only the page content and its required assets, without
site administration chrome (e.g. the admin toolbar). The preview MUST be offered
for page content only, not for config-entity layouts.

#### Scenario: No admin chrome in preview

- **WHEN** the preview renders
- **THEN** the rendered output MUST include the page's component assets
- **AND** MUST NOT include the administration toolbar

#### Scenario: No preview for config layouts

- **WHEN** the editor is translating a config-entity layout
- **THEN** the system MUST NOT offer the page preview

#### Scenario: Preview denied without page update access

- **WHEN** a user with the workspace permission but without update access to a page requests its preview
- **THEN** the system MUST deny access
