# Goodwell Insurance — Site Template

A Drupal 11 site template for insurance businesses. Built with the Canvas page builder, Goodwell theme, and Webform.

---

## What's Included

- **Goodwell theme** — custom front-end theme with 25+ Canvas components (hero, cards, premium calculator, testimonials, webform embed, and more)
- **Canvas page builder (1.8+)** — drag-and-drop layouts using Single Directory Components
- **Webform** — lead capture forms with modal thank-you messages
- **Premium Calculator** — live insurance estimator with no form submission required
- **Gin admin theme** — modern admin interface
- Pre-configured content types: `canvas_page`, `media`, `webform_submission`

---

## Requirements

- PHP 8.2+
- Drupal 11.3+
- Canvas 1.8+
- Composer 2
- Drush 13+
- [Lando](https://lando.dev) or [DDEV](https://ddev.com) recommended for local development

---

## Installation

Always run a clean Drupal install **before** applying the recipe.

```bash
# With Lando
lando drush si -y
lando drush recipe recipes/goodwell

# With DDEV
ddev drush si -y
ddev drush recipe recipes/goodwell
```

Then clear cache:

```bash
lando drush cr
```

---

## After Installation

- Log in at `/user/login`
- Edit pages via the Canvas editor (pencil icon on any canvas_page node)
- Manage webforms at `/admin/structure/webform`
- Manage media at `/admin/content/media`
