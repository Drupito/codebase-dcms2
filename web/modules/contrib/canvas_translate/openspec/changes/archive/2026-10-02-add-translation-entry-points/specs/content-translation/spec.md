## ADDED Requirements

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
