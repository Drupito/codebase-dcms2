# ai-translation

## ADDED Requirements

### Requirement: Optional AI submodule

AI machine translation MUST be delivered as a submodule (`canvas_translate_ai`)
that depends on the Drupal AI module, so that the AI dependency is optional. The
parent `canvas_translate` module MUST NOT depend on, or reference in its service
container, the Drupal AI module.

#### Scenario: AI module is not enabled

- **WHEN** the `canvas_translate_ai` submodule is not enabled
- **THEN** `canvas_translate` MUST function fully without it
- **AND** no Drupal AI service MUST be required to build the `canvas_translate` container

#### Scenario: Enabling AI is the opt-in

- **WHEN** an operator enables the `canvas_translate_ai` submodule
- **THEN** the AI translation endpoints and editor controls MUST become available

### Requirement: AI translation endpoint

The submodule MUST expose endpoints that accept a list of translatable strings
(each carrying a key, an optional text format, and the source text) for a content
page or a config entity and a target language, and return a suggested target value
per key. The endpoints MUST require the `translate canvas content` permission and
update access to the target entity. The endpoints MUST NOT modify any stored
content; they only return suggestions.

#### Scenario: Translating a set of strings

- **WHEN** an authorized editor requests AI translation of a set of source strings for a target language
- **THEN** the system MUST return a target value for each translatable source string, keyed by the supplied key
- **AND** the stored page, drafts, and translations MUST be unchanged

#### Scenario: Empty source strings are skipped

- **WHEN** the requested set includes a string whose source text is empty
- **THEN** the system MUST NOT request a translation for it and MUST omit it from the result

#### Scenario: Unauthorized request is denied

- **WHEN** a user without the `translate canvas content` permission or without update access to the entity requests AI translation
- **THEN** the system MUST deny access

### Requirement: Translation engine

The submodule MUST perform translation through the Drupal AI `translate_text`
operation using the site's configured default provider and model for that
operation. Rich-text strings (those carrying a text format) MUST be sent verbatim
so embedded markup is preserved by the provider.

#### Scenario: No translate-text provider configured

- **WHEN** no default provider is configured for the `translate_text` operation
- **THEN** the system MUST fail the request with an error explaining that a Translate Text provider must be configured in Drupal AI settings
- **AND** the editor MUST surface that explanation to the user

### Requirement: AI availability is advertised to the editor

The side-by-side editor responses MUST indicate whether AI assistance is available
(i.e. the `canvas_translate_ai` submodule is enabled), so the editor shows AI
controls only when they will work.

#### Scenario: AI controls hidden when unavailable

- **WHEN** the editor loads a page or config entity for translation and AI is unavailable
- **THEN** the editor MUST NOT show AI translation controls

### Requirement: AI-assisted editing in the editor

When AI is available, the editor MUST offer a bulk "AI translate" action and a
per-row AI action. The bulk action MUST default to translating only rows whose
target is empty and MUST also offer re-translating all rows. AI results MUST be
applied to the editable target fields as an in-progress draft for the editor to
review, edit, and publish — never published automatically.

#### Scenario: Bulk translate fills empty rows

- **WHEN** the editor triggers the bulk AI action with the default scope
- **THEN** the system MUST request translations only for rows whose target is currently empty
- **AND** the returned values MUST populate those target fields and be saved as a draft, not published

#### Scenario: Re-translate all

- **WHEN** the editor triggers the bulk AI action with the re-translate-all option
- **THEN** the system MUST request translations for every row that has source content

#### Scenario: Per-row translate

- **WHEN** the editor triggers the AI action on a single row
- **THEN** the system MUST request a translation for only that row and populate its target field as a draft
