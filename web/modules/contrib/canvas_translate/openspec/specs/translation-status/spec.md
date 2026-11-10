# translation-status

## Purpose

Give editors an at-a-glance view of how complete and how current each
translation is, across the whole site and within a single page, so they can see
what still needs work and which translations have drifted from the source.

## Requirements

### Requirement: Per-language translation status

The system MUST report, for each page and language, exactly one status:
`not_translated`, `draft`, `up_to_date`, or `outdated` (the source language is
reported as `source`).

#### Scenario: No translation and no draft

- **WHEN** a language has neither a published translation nor a draft
- **THEN** the system MUST report `not_translated`

#### Scenario: Draft in progress

- **WHEN** a language has an unpublished draft
- **THEN** the system MUST report `draft`

#### Scenario: Published and current

- **WHEN** a language has a published translation that is not flagged outdated and has no draft
- **THEN** the system MUST report `up_to_date`

### Requirement: Completeness percentage

The system MUST report a completeness percentage per page and language, counting
a prop as translated only when its target value is non-empty and differs from the
source. Props whose source is empty MUST NOT count toward the total.

#### Scenario: Partially translated page

- **WHEN** half of a page's translatable props (by count of props with source content) are translated to values differing from the source
- **THEN** the system MUST report approximately 50% complete

#### Scenario: Values equal to source do not count as translated

- **WHEN** a prop's target value equals its source value
- **THEN** that prop MUST NOT count toward completeness

### Requirement: Outdated tracking

When the translatable source content of a page changes, the system MUST flag the
page's existing translations as outdated; publishing a translation MUST clear its
outdated flag.

#### Scenario: Source content changes

- **WHEN** the source (default) translation's translatable strings change and the page is saved
- **THEN** the system MUST mark each existing non-source translation as outdated

#### Scenario: Non-translatable change does not flag outdated

- **WHEN** the source changes only in non-translatable ways (e.g. layout)
- **THEN** the system MUST NOT mark translations outdated

#### Scenario: Republishing clears outdated

- **WHEN** an outdated translation is published
- **THEN** the system MUST clear its outdated flag
