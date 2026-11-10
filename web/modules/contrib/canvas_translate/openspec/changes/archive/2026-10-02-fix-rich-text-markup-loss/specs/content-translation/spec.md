## MODIFIED Requirements

### Requirement: Side-by-side editor

The system MUST present, per page and target language, the translatable strings
grouped by component, each as a read-only source value beside an editable target
value. Plain-text props MUST use a text field; rich-text props (those carrying a
text format) MUST use the editor that text format configures, with the same
features and allowed markup as in Canvas.

#### Scenario: Opening a page for a target language

- **WHEN** the editor opens a page for a target language
- **THEN** the system MUST list every translatable string with its source value and current target value, grouped by component

#### Scenario: Rich text uses the format's editor

- **WHEN** a rich-text prop's text format uses CKEditor 5
- **THEN** its target value MUST be edited in CKEditor 5 configured by that format
- **AND** markup the format allows (such as headings, embedded media, or a managed link's `data-entity-*` attributes) MUST be kept in the editor

## ADDED Requirements

### Requirement: Rich-text editing never destroys markup

The translation editor MUST NOT load a rich-text value into an editor that
would drop any of its elements, attributes, or text. Such a value MUST be
edited as HTML instead, and MUST survive edit-and-publish cycles unchanged.

#### Scenario: Value the editor cannot represent

- **WHEN** a rich-text target value contains markup the format's editor would drop, whether loaded, copied from source, or produced by AI translation
- **THEN** the value MUST NOT be loaded into that editor
- **AND** the field MUST be edited as HTML, with a notice explaining why
- **AND** publishing MUST write exactly the HTML the translator left

#### Scenario: Editing another field leaves the value alone

- **WHEN** a rich-text value is edited as HTML and the translator edits a different field on the page
- **THEN** the rich-text value MUST be unchanged

#### Scenario: Normalization is not loss

- **WHEN** the editor only rewrites markup without changing the rendered result (such as `<b>` to `<strong>`, paragraph wrapping, whitespace, or an added class)
- **THEN** the value MUST count as representable and be edited in the editor

#### Scenario: No editor available for the format

- **WHEN** a rich-text prop's format has no CKEditor 5 editor, or the editor fails to load
- **THEN** the field MUST be edited as HTML rather than in a substitute editor
