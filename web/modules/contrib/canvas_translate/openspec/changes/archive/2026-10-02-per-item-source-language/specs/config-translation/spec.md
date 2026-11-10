## MODIFIED Requirements

### Requirement: Config layouts in the workspace

The system MUST list translatable config-entity layouts (content templates and
page regions) in the same dashboard as pages, distinguished by type, and MUST
open them in the same side-by-side editor. A config layout's source language is
the language of the layout itself, which is not necessarily the site default
language; the system MUST report that langcode as the layout's source and MUST
compute its per-language status against it. The system MUST reject a request to
translate a layout into its own source language, and MUST accept a request to
translate it into the site default language when that is not its source.

#### Scenario: Listing a page region

- **WHEN** a page region with translatable component strings exists
- **THEN** the dashboard MUST list it with its type and per-language status

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

## ADDED Requirements

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
