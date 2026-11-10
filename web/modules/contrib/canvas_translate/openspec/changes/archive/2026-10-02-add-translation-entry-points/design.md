## Context

`TranslateRouteSubscriber` already swaps the controller of
`entity.canvas_page.content_translation_overview` (core's Translate operation
and tab) for `TranslateRedirectController`, which redirected to the extension's
root, the dashboard. The page id was dropped on the way.

The extension is a SPA booted at `/canvas/app/canvas_translate`. Canvas
forwards the path after that prefix to the extension iframe as its hash route
(`CanvasPathProcessor`), so a Drupal URL such as
`/canvas/app/canvas_translate/page/5/fr` lands on the SPA route
`/page/:id/:langcode`.

Listings such as the pages view render one row per translation. Core's "Edit"
operation on a `canvas_page` opens the Canvas editor, which always edits the
original because it strips language prefixes.

The Canvas editor's language switcher (`LanguageSelect.tsx`) reads
`translations.links[langcode]` from the layout response
(`canvas.api.layout.get`). For each language it renders "Edit translation"
when an `edit-form` link is present, "Add translation" when a `create` link is
present, and "Delete translation" for `delete-form`, and opens the first two
in a new tab. `CanvasUriDefinitions` defines `LINK_REL_EDIT` and
`LINK_REL_CREATE`, but `ApiLayoutController::get()` only emits `delete-form`.
The client is built for another module to supply the other two, but Canvas
has no hook or event for it.

## Goals / Non-Goals

**Goals:**
- Keep the page context when entering Canvas Translate from Drupal.
- Make the row operations of a translation say what they open, and offer
  editing that translation.

**Non-Goals:**
- A Canvas Translate entry in the Canvas sidebar, and opening the switcher's
  links in the same tab. These need Canvas changes.
- Replacing or restyling core's translation overview table for other entity
  types.
- Making core's "Edit" open the translation. The Canvas editor has no
  translation editing mode.

## Decisions

### Per-page overview as an extension route, not a Drupal page

The overview is a new SPA route (`/page/:id`) next to the editor route
(`/page/:id/:langcode`). It reuses the dashboard's data, status badges, and
completion rendering.

Alternative considered: a server-rendered Drupal page (like core's overview
table). Rejected: it would duplicate the status computation in a second
renderer, and editors would cross from the Drupal admin into the extension on
every language click.

### Overview data from the existing items endpoint with `?id=`

`items()` adds an entity query condition on `id` and skips config items when
the filter is set. Access checking, status computation, and the response shape
are unchanged.

Alternative considered: a new single-page endpoint. Rejected: it would need
the same status computation and response shape, so it would be a second copy
of `items()`.

### Deep links built in one place

`ExtensionUrl::forPage($id, ?$langcode)` builds
`base:canvas/app/canvas_translate/page/{id}[/{langcode}]`. Both the redirect
and the row operations use it, so the SPA route shape is defined once on the
PHP side. `base:` keeps the URL free of the language prefix, which Canvas does
not route.

### Row operations through `hook_entity_operation` and its alter hook

`hook_entity_operation` adds "Edit translation" with weight 0, before core's
"Edit" (10), so it becomes the dropbutton's primary action.
`hook_entity_operation_alter` relabels core's "Edit" to "Edit original". Both
apply only when the entity is a `Page` and not the default translation.

The relabel applies regardless of the `translate canvas content` permission,
because "Edit" opens the original for every user. "Edit translation" requires
that permission and update access to the page, matching the editor's own
access.

Alternative considered: removing core's "Edit" on translation rows. Rejected:
editing the original from a translation row is still a valid action, and
removing it hides an existing feature.

### Language switcher links by decorating `ApiLayoutController`

Canvas registers `ApiLayoutController` as a service under its class name, and
Drupal's controller resolver loads a route's `_controller` from the container
when a service with that id exists. A service decorator therefore replaces the
controller on every Canvas route that uses it, and `final` does not prevent
this because the decorator wraps rather than extends.

The decorator's `get()` calls the inner controller and, when the entity is a
`canvas_page` and the user has `translate canvas content` and update access,
adds to `translations.links[langcode]` for each configured language other than
the page's source language: `edit-form` when the page has that translation,
`create` when it does not. Both point to `ExtensionUrl::forPage($id,
$langcode)`. Existing keys, including Canvas's `delete-form`, are kept.
Because `PreviewEnvelope::$additionalData` is `readonly`, `get()` returns a new
envelope with the inner one's render array, data, and status code. Every
other public method the Canvas routes reference (`patch()`, `post()`,
`getLabel()`, `draftContentTemplate()`) passes through unchanged. Content
templates and patterns pass through untouched, since Canvas Translate handles
templates in its own dashboard.

Acquia's `haas_translation` module uses the same decorator in production on
Canvas 1.11, which is the evidence that this is the intended extension point
for the client's `edit-form` and `create` links.

This depends on Canvas internals (the controller's service id and the shape of
its response), so the decorator carries an `UPSTREAM(canvas):` marker. The
minimal upstream fix: `ApiLayoutController::get()` emits `edit-form` and
`create` from the entity's content_translation routes
(`entity.canvas_page.content_translation_edit` and `_add`) where the user has
access. Canvas Translate would then redirect those routes to its editor, as
`TranslateRouteSubscriber` already does for the overview, and the decorator is
deleted.

Alternatives considered:
- A `kernel.response` subscriber that decodes the layout JSON and adds the
  links. Rejected: it decodes and re-encodes the whole response, preview HTML
  included, on every editor load, and couples to the serialized shape instead
  of the PHP array.
- Changing `canvas_page`'s `edit-form` link template. Rejected: the switcher
  does not read link templates, and the template also drives core's "Edit"
  and Canvas's own navigation to the original.
- Offering "Add translation" only on published pages, as `haas_translation`
  does. Not needed: Canvas Translate does not restrict translating unpublished
  pages.

## Risks / Trade-offs

- [Other modules alter the same operations] → The hooks only add a keyed
  operation and change the title of `edit`; they don't remove or reorder
  others.
- [The SPA route shape changes] → `ExtensionUrl` and `App.tsx` reference each
  other in comments, and the E2E spec asserts the redirect URL and the editor
  URL.
- [Canvas adds a public method to `ApiLayoutController` that a route uses] →
  The decorator has no such method, so that route fails. A forwarding
  `__call()` does not help, because Symfony reflects on the concrete method
  to resolve arguments. A kernel test asserts the decorator has every
  `ApiLayoutController` method a route's `_controller` references, so the gap
  fails CI on a Canvas update.
- [Canvas changes the layout response shape or the service id] → A kernel
  test asserts the links in the layout response, so either change fails CI
  instead of breaking the switcher.
- [A page id the user can't access] → The items query keeps
  `accessCheck(TRUE)`, so the overview gets an empty list and shows its empty
  state. The redirect route keeps core's access checks.
