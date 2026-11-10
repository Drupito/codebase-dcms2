# Goodwell Theme

Front-end theme for the Goodwell insurance site template, built on Drupal 11 with the Canvas module. Uses Single Directory Components (SDC) for all UI elements.

---

## Requirements

- Drupal 11.3+
- Canvas module
- CVA module (`cva:cva` dependency)

---

## Structure

```
goodwell_theme/
├── build/
│   └── main.min.css          # Compiled production stylesheet
├── components/               # Single Directory Components (SDC)
│   ├── accordion/
│   ├── body/
│   ├── brand-band/
│   ├── button/
│   ├── card-article/
│   ├── card-plan/
│   ├── card-team/
│   ├── contact-info/
│   ├── feature-item/
│   ├── footer/
│   ├── heading/
│   ├── hero-section/
│   ├── image/
│   ├── logo/
│   ├── navbar/
│   ├── premium-calculator/
│   ├── price-card/
│   ├── section/
│   ├── section-grid/
│   ├── section-intro/
│   ├── simple-hero/
│   ├── social-links/
│   ├── testimonial-slider/
│   ├── text/
│   ├── value-item/
│   ├── webform-embed/
│   └── ...
├── src/
│   ├── theme.css             # Design tokens (CSS variables)
│   ├── base.css              # Global base styles
│   ├── fonts.css             # Font imports
│   └── modal.css             # jQuery UI dialog / webform modal styles
├── goodwell_theme.info.yml
└── goodwell_theme.libraries.yml
```

---

## Components

Each component lives in its own directory with up to 4 files:

| File | Purpose |
|---|---|
| `*.component.yml` | Schema — defines props, slots, and Canvas field mappings |
| `*.twig` | Template |
| `*.css` | Scoped styles |
| `*.js` | Optional interactivity (accordion, navbar, premium calculator) |

### Available components

| Component | Description |
|---|---|
| `accordion` | Expand/collapse FAQ panels |
| `body` | Rich text body content |
| `brand-band` | Logo strip / partner band |
| `button` | CTA button — variants: primary, secondary, ghost |
| `card-article` | Blog/article card with image, title, excerpt |
| `card-plan` | Insurance plan card with pricing |
| `card-team` | Team member card |
| `contact-info` | Address, phone, email block |
| `feature-item` | Icon + heading + description item |
| `footer` | Site footer |
| `heading` | Standalone heading with level control |
| `hero-section` | Full-width hero with background image, badge, awards, card slot |
| `image` | Responsive image wrapper |
| `logo` | Site logo |
| `navbar` | Navigation bar with mobile menu |
| `premium-calculator` | Live insurance premium estimator with sliders |
| `price-card` | Pricing tier card |
| `section` | Layout wrapper with background and padding control |
| `section-grid` | Responsive grid layout wrapper |
| `section-intro` | Centred heading + description + CTA area |
| `simple-hero` | Minimal hero with background image, heading, subheading |
| `social-links` | Social media icon links |
| `testimonial-slider` | Customer testimonial carousel |
| `text` | Plain text / paragraph block |
| `value-item` | Icon + value proposition item |
| `webform-embed` | Webform embedded in a styled card |

---

## Design Tokens

All colours, spacing, typography, and radii are defined as CSS variables in `src/theme.css`. Edit this file to retheme the site without touching individual components.

Key tokens:

```css
--color-primary:       #034A46;   /* Dark teal */
--color-primary-mid:   #1c7e6e;   /* Mid teal */
--color-accent:        #7ed957;   /* Bright green CTA */
--color-surface:       #ffffff;
--color-bg:            #edf6f5;
--color-text:          #000000;
--color-text-muted:    #5f5f5f;
```

---

## Modals

`src/modal.css` styles Drupal's jQuery UI dialog used by the Webform module in modal mode. It overrides Gin admin theme styles to keep the modal white and on-brand.

To open a webform in a modal, configure the webform's **Ajax settings** to use modal, then link to it normally. The modal styles apply automatically.

---

## Premium Calculator

The `premium-calculator` component is a standalone live estimator — no form submission required. It uses vanilla JS to calculate an indicative monthly premium as the user adjusts the sliders.

**Formula:**
```
monthly = cover_amount × 0.0007 × age_factor × gender_factor ÷ 12

age_factor    = 1 + (age - 18) × 0.04
gender_factor = female ? 0.9 : 1.0
```

Props available in Canvas editor:

| Prop | Description |
|---|---|
| `heading` | Card title |
| `cta_label` | Button text |
| `cta_url` | URL the button links to (e.g. lead capture form) |

---

## CSS Patches Required

The following Composer patches must be present in the root `composer.json` for Canvas to work correctly with recipe imports:

```json
"patches": {
    "drupal/canvas": {
        "Filter null entities in calculateDependencies": "patches/canvas-filter-null-entities-in-calculate-dependencies.patch",
        "Fix UriOverride double-encoding file URIs on export": "patches/canvas-fix-uri-override-double-encoding.patch"
    }
}
```

---

## Clearing Cache

After any change to component files or `src/*.css`, run:

```bash
lando drush cr
# or on the server:
drush cr
```
