# canvas-core-alignment

## Purpose

Keep Canvas Translate a **thin layer on top of Canvas and Drupal core**, not a
parallel reimplementation of them. Every line this module copies, forks, or
reinvents from Canvas/core is a line that drifts out of sync the next time the
upstream changes — and a reason a Canvas maintainer rejects the module. This
capability makes "minimize what we override" a hard, testable rule for **all**
work in this module going forward, and makes each unavoidable override a tracked,
greppable upstream ask rather than silent debt.

This is a governance capability: it constrains *how* the other capabilities
(`content-translation`, `config-translation`, `translation-status`,
`translation-preview`, `ai-translation`) are implemented. It does not add
user-facing behavior.

## Requirements

### Requirement: Prefer existing Canvas/core APIs over bespoke code

Implementations MUST use a public Canvas or Drupal core API when one exists for
the task (extraction, persistence, publish, revisioning, staleness, access,
CSRF, language negotiation). Bespoke code that duplicates an existing
Canvas/core mechanism MUST NOT be introduced. A change that reinvents a capability
already provided upstream is incomplete.

#### Scenario: A core mechanism already covers the need

- **WHEN** a contributor needs behavior that Canvas or Drupal core already
  provides through a public API
- **THEN** the implementation MUST call that API rather than reimplement it

#### Scenario: Reinvention is proposed in review

- **WHEN** a change adds code that duplicates an existing Canvas/core capability
- **THEN** the change MUST be treated as incomplete until it either reuses the
  upstream capability or records why it cannot (see the upstream-marker
  requirement)

### Requirement: No forking, and no dependency on non-public Canvas APIs

Production code MUST NOT copy or fork Canvas or Drupal core classes, and MUST NOT
depend on Canvas modules or APIs marked `hidden`, `@internal`, or designated for
test/dev use. When a needed Canvas capability is only reachable through such a
surface, the module MUST file an upstream marker (below) instead of forking or
coupling to the unstable surface.

#### Scenario: Needed logic lives in a non-public class

- **WHEN** the logic a contributor needs is only available by extending or
  copying a Canvas/core class that cannot be loaded or relied on independently
- **THEN** the contributor MUST NOT fork it, and MUST record an upstream marker
  describing the minimal change that would make a public API available

#### Scenario: Dependency on a hidden or test module is added

- **WHEN** a change adds a dependency on a Canvas module that is `hidden` or
  documented as test/dev only
- **THEN** the change MUST carry an upstream marker requesting promotion of the
  needed behavior to a supported API, and MUST NOT be considered production-ready
  while the dependency stands

### Requirement: Every override carries a greppable upstream marker

Each remaining fork or coupling to an unstable Canvas/core surface MUST be
annotated in the code with an `UPSTREAM(canvas):` marker. The marker MUST
state (1) what is being forked/reinvented and why it is currently unavoidable,
(2) the minimal upstream change that would remove the need, and (3) what this
module deletes once that change lands. The token `UPSTREAM(canvas)` MUST be used
verbatim so the full ledger is harvestable with a single search.

#### Scenario: An unavoidable fork is committed

- **WHEN** a change introduces or retains code that forks or reinvents Canvas/core
- **THEN** that code MUST include an `UPSTREAM(canvas):` marker naming the
  coupling, the minimal upstream fix, and the code that the fix would delete

#### Scenario: Harvesting the upstream ledger

- **WHEN** a maintainer searches the codebase for `UPSTREAM(canvas)`
- **THEN** every active override MUST appear, each with its minimal upstream fix

### Requirement: Markers are removed when the upstream change lands

When a Canvas/core change removes the need for an override, the module MUST delete
both the override code and its `UPSTREAM(canvas):` marker in that same change set.
A marker MUST NOT outlive the override it describes, and an override MUST NOT
outlive its marker.

#### Scenario: Upstream fix becomes available

- **WHEN** Canvas or core ships the API named in an `UPSTREAM(canvas):` marker
- **THEN** the module MUST switch to that API and remove the forked code and the
  marker in the same change

#### Scenario: Override changes behavior

- **WHEN** the behavior of an annotated override changes
- **THEN** its `UPSTREAM(canvas):` marker MUST be updated in the same change to
  stay accurate
