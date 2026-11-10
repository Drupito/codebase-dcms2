# config-translation

## Purpose

Extend the translation workspace to Canvas config-entity layouts (content
templates, page variants, and the page regions page variants replaced) so their
translatable component strings can be translated alongside page content, using
Drupal's configuration language overrides.
## Requirements
### Requirement: Config layouts in the workspace

The system MUST list translatable config-entity layouts (content templates,
page variants, and page regions) in the same dashboard as pages, distinguished
by type, and MUST open them in the same side-by-side editor. Page regions MUST NOT be listed once Canvas has a default page variant,
because Canvas no longer renders them. A config layout's source language is the
language of the layout itself, which is not necessarily the site default
language; the system MUST report that langcode as the layout's source and MUST
compute its per-language status against it. The system MUST reject a request to
translate a layout into its own source language, and MUST accept a request to
translate it into the site default language when that is not its source.

#### Scenario: Listing a page region

- **WHEN** a page region with translatable component strings exists
- **AND** Canvas has no default page variant
- **THEN** the dashboard MUST list it with its type and per-language status

#### Scenario: Listing a page variant

- **WHEN** a page variant with translatable component strings exists
- **THEN** the dashboard MUST list it as a page template with its per-language status

#### Scenario: Regions superseded by a default page variant

- **WHEN** Canvas has a default page variant
- **THEN** the dashboard MUST NOT list page regions

#### Scenario: Listing a layout authored in a non-default language

- **WHEN** the dashboard lists a config layout whose language is not the site default language
- **THEN** the system MUST report that layout's own langcode as its source language
- **AND** the site default language MUST be reported as one of its target languages

#### Scenario: Translating a layout into the site default language

- **WHEN** the editor opens a config layout whose source language is not the site default, targeting the site default language
- **THEN** the system MUST serve the side-by-side editor for that target language

#### Scenario: Translating a layout into its own source language is rejected

- **WHEN** the editor requests a config layout for a target language equal to that layout's own source language
- **THEN** the system MUST reject the request with a client error
- **AND** the error MUST state that the source language is edited in the Canvas editor instead

### Requirement: Config translation storage via language overrides

The system MUST store config-layout translations as configuration language
overrides for the target language, leaving the active (source) configuration
unchanged.

#### Scenario: Saving a config translation

- **WHEN** the editor saves a translated string for a config layout in a target language
- **THEN** the system MUST write that string into the target-language configuration override
- **AND** the active source configuration MUST be unchanged

#### Scenario: Reading an existing config translation

- **WHEN** the editor opens a config layout that already has a target-language override
- **THEN** the editor MUST show the override values as the current target values

### Requirement: Config translation staleness

The system MUST report a config layout's translation as outdated when its source
translatable strings have changed since the translation was last saved.

#### Scenario: Source strings change after translation

- **WHEN** a config layout's source translatable strings change after a target-language override was saved
- **THEN** the system MUST report that target language as outdated

### Requirement: Config draft approval

Config translation drafts MUST participate in the same approval lifecycle as
content drafts. A reviewer MUST be able to approve a pending config draft,
and the approved flag MUST be cleared automatically when the draft changes,
is discarded, or is published. Approving with no pending draft MUST return 400.
Access is enforced by the same update-access check applied to all config
translation mutations.

#### Scenario: Approving a pending config draft

- **WHEN** a reviewer approves a pending config translation draft
- **THEN** the system MUST set the draft's approved flag to TRUE
- **AND** the flag MUST be surfaced on the editor document and on the dashboard cell

#### Scenario: Approving config with no draft is rejected

- **WHEN** approve is called and no draft exists for that config entity + language
- **THEN** the system MUST return 400 Bad Request

#### Scenario: Editing the config draft clears the approval

- **WHEN** a config draft is saved after it was approved
- **THEN** the approved flag MUST be cleared

#### Scenario: Discarding the config draft clears the approval

- **WHEN** an approved config draft is discarded
- **THEN** the approved flag MUST be cleared together with the draft

#### Scenario: Publishing the config draft clears the approval

- **WHEN** an approved config draft is published
- **THEN** the approved flag MUST be cleared; the editor document reports not-approved

#### Scenario: Unapprove withdraws a config approval

- **WHEN** a reviewer calls unapprove on an approved config draft
- **THEN** the system MUST clear the approved flag and return approved: FALSE

### Requirement: Config source strings are read override-free

The system MUST derive a config layout's source translatable strings from the
active configuration with language configuration overrides not applied, so that
an existing target-language override can never be mistaken for source content.
This applies wherever source strings are used: listing, completeness,
staleness, and the side-by-side editor.

#### Scenario: An existing override does not become the source

- **WHEN** a config layout has a saved target-language override and the editor opens the dashboard or that layout
- **THEN** the source strings the system reports MUST be the active configuration's strings
- **AND** the reported completeness MUST be unchanged by the presence of that override

#### Scenario: Staleness is measured against the active configuration

- **WHEN** a config layout's active source strings change after a target-language override was saved
- **THEN** the system MUST report that target language as outdated

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
updated, and MUST survive a config import that recreates or removes the
variant. A config import MUST leave the variant's translations as exported.

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

#### Scenario: Publishing a moved draft keeps other regions' translations

- **WHEN** region drafts move into a variant that had no draft, and another
  region of the variant only has a published translation
- **THEN** the variant draft MUST also hold that published translation, so
  publishing the draft keeps it
- **AND** a region's own draft MUST still decide its text, including text it
  cleared

#### Scenario: Approval only moves when everything moved was approved

- **WHEN** drafts from several regions merge into one variant draft
- **THEN** the variant draft MUST be approved only if every region draft was
  approved and the variant had no draft of its own
- **AND** a draft that changed MUST lose its approval: a region draft that only
  partly moved, or a variant draft that gained entries

#### Scenario: The variant UUID follows the config export

- **WHEN** the database update runs and the config sync directory has a
  `theme_<theme>` variant with another UUID than the active one
- **THEN** the active variant MUST get the UUID from the sync directory
- **AND** its configuration and language overrides MUST stay unchanged
- **AND** a config import that follows MUST update the variant, not recreate it

#### Scenario: A config import recreates the variant

- **WHEN** a config import deletes and recreates a `theme_<theme>` variant, for
  example because each site created it with its own UUID during the Canvas
  update
- **THEN** the variant's drafts and approvals MUST be kept
- **AND** the system MUST NOT write language overrides during the import

#### Scenario: A config import deletes a translatable config entity

- **WHEN** a config import deletes a content template, page region, or page
  variant and does not create it again
- **THEN** once the import completes, the system MUST delete its drafts

#### Scenario: A config import does not merge region translations

- **WHEN** a config import runs, whether or not it creates the variant or
  brings region translations
- **THEN** the system MUST NOT add region translations to the variant
- **AND** a variant translation a translator cleared MUST stay cleared
- **AND** the active configuration MUST match the export after the import
