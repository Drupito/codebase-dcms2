# Hourglass Theme

**Hourglass** is a modern hourglass theme for Drupal 11, built entirely with Single Directory Components (SDC). It features a clean orange brand palette, responsive layouts, modular component architecture, and full Canvas/Site Template integration for flexible page building.

## Features

- **Single Directory Components (SDC)**: Built utilizing Drupal core's SDC architecture, keeping Twig, CSS, and JS grouped together in component directories.
- **Esbuild**: A lightning-fast CSS/JS compiler and minifier. Minified assets are compiled to the `dist/` directory and loaded via library declarations.
- **Third-Party Libraries**: Integrates `Splide` (for sliders/carousels) and `GSAP` (for scroll-driven animations).
- **Responsive Layouts**: Designed to be fully responsive, scaling seamlessly from desktop to mobile viewports.

## Installation & Setup

To use the theme for local development:

1. Navigate to the theme directory:
   ```bash
   cd web/themes/contrib/hourglass
   ```
2. Install the required development dependencies:
   ```bash
   npm install
   ```
3. Build the theme assets:
   ```bash
   npm run build
   ```

## Development & Asset Compilation

Asset compilation is handled via esbuild:
- **Build**: Compiles and minifies all `.css` and `.js` source files (including component-specific styles and scripts) into the `dist/` directory:
  ```bash
  npm run build
  ```
- **Drupal Cache**: After building new assets or modifying templates, clear the Drupal cache to see changes:
  ```bash
  lando drush cr
  # or
  ddev drush cr
  ```

## Component Architecture

Components are grouped under the `components/` directory:
- **Cards** (`components/cards/`): Reusable card sub-components (e.g., `offering-card`, `logo-item`, `stat-card`).
- **Modules** (`components/modules/`): Page sections and layout blocks (e.g., `hero`, `offerings-slider`, `logo-carousel`, `apply-section`).

Each component contains:
- `[component-name].twig` - Twig template defining the markup and slots/props.
- `[component-name].css` - Source stylesheet.
- `[component-name].js` - Source JavaScript (if applicable).
- `[component-name].component.yml` - SDC metadata file.

Minified assets (`*.min.css` and `*.min.js`) are built into `dist/components/` and registered under corresponding entries in `hourglass.libraries.yml`.
