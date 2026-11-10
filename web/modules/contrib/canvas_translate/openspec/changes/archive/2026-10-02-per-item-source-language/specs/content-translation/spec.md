## MODIFIED Requirements

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
