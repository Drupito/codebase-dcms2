## MODIFIED Requirements

### Requirement: Config layouts in the workspace

The system MUST list translatable config-entity layouts (content templates,
page variants, and page regions) in the same dashboard as pages, distinguished
by type, and MUST open them in the same side-by-side editor. Layout types the
installed Canvas does not provide MUST be skipped rather than reported as an
error. Page regions MUST NOT be listed once Canvas has a default page variant,
because Canvas no longer renders them.

#### Scenario: Listing a page region

- **WHEN** a page region with translatable component strings exists
- **AND** Canvas has no default page variant
- **THEN** the dashboard MUST list it with its type and per-language status

#### Scenario: Listing a page variant

- **WHEN** a page variant with translatable component strings exists
- **THEN** the dashboard MUST list it as a page template with its per-language status

#### Scenario: A layout type the installed Canvas does not have

- **WHEN** Canvas does not provide one of the supported layout types
- **THEN** the dashboard MUST omit it and list the remaining types normally

#### Scenario: Regions superseded by a default page variant

- **WHEN** Canvas has a default page variant
- **THEN** the dashboard MUST NOT list page regions

## ADDED Requirements

### Requirement: Canvas with page variants is required

The module MUST declare a dependency on the Canvas version that introduces page
variants (Canvas 1.11), so it cannot be updated ahead of Canvas.

#### Scenario: Updating Canvas Translate on a Canvas without page variants

- **WHEN** a site runs a Canvas version older than 1.11
- **THEN** Composer MUST refuse to install this version of Canvas Translate
- **AND** Drupal MUST report Canvas as an unresolved dependency

### Requirement: Page region translation state follows the page variant

The system MUST move this module's translation state for page regions to the
page variant Canvas converts them into (Canvas 1.11, variant ID
`theme_<theme>`), matched by component UUID: pending
drafts, approvals, and published translations the variant does not have yet.
Regions whose components are not in the variant MUST be left alone. This MUST
work whether Canvas converts the regions before or after this module is
updated.

#### Scenario: Canvas converts regions while this module is installed

- **WHEN** Canvas creates a `theme_<theme>` page variant from the theme's regions
- **THEN** the system MUST move the regions' drafts, approvals, and published
  translations to the variant at once

#### Scenario: Canvas was updated first

- **WHEN** a `theme_<theme>` variant already exists and regions still hold
  drafts or published translations from before this module was updated
- **THEN** the post update MUST move them to the variant
- **AND** running it again MUST change nothing

#### Scenario: A region Canvas did not convert

- **WHEN** a region's components are not in the variant
- **THEN** its drafts and published translations MUST stay on the region

#### Scenario: The variant already has a newer translation

- **WHEN** both the region and the variant have a published translation for the
  same component
- **THEN** the variant's translation MUST be kept

#### Scenario: Approval only moves when everything moved was approved

- **WHEN** drafts from several regions merge into one variant draft
- **THEN** the variant draft MUST be approved only if every region draft was
  approved and the variant had no draft of its own
