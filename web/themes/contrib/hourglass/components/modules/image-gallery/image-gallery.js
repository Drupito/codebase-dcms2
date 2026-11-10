/**
 * @file
 * Image Gallery — thumbnail sidebar drives a cross-fade main view.
 *
 * Gallery Item cards render directly in the visible sidebar.
 * JS clones each item's thumbnail image (already loaded, already in the DOM)
 * into the main view as an absolutely-stacked slide. Clicking a thumbnail
 * cross-fades to that slide and updates the caption.
 *
 * Using the visible thumbnail as the clone source means:
 *   - Images are guaranteed loaded before they appear in the main view.
 *   - No hidden-element / lazy-load timing issues.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.imageGallery = {
    attach(context) {
      once('image-gallery', '.js-image-gallery', context).forEach((gallery) => {
        const thumbList = gallery.querySelector('.js-gallery-thumbs');
        const mainView  = gallery.querySelector('.js-gallery-main');
        const captionEl = gallery.querySelector('.js-gallery-caption');

        if (!thumbList || !mainView || !captionEl) return;

        const items = Array.from(thumbList.querySelectorAll('[data-gallery-item]'));
        if (!items.length) return;

        let activeIndex = 0;

        // ── Build main slides by cloning the visible thumbnail content ───
        // The thumbnail is already rendered and loaded — safe to clone.
        items.forEach((item, i) => {
          const thumbSource = item.querySelector('[data-gallery-item-thumb]');
          if (!thumbSource) return;

          const slide = document.createElement('div');
          slide.className = 'c-image-gallery__slide';
          slide.dataset.slide = i;
          slide.setAttribute('aria-hidden', i === 0 ? 'false' : 'true');
          if (i === 0) slide.classList.add('is-active');

          // Clone inner content — not the thumb-img wrapper (it has height/overflow constraints)
          thumbSource.childNodes.forEach((node) => slide.appendChild(node.cloneNode(true)));

          // Ensure cloned images load at full quality (remove lazy / fixed dimensions)
          slide.querySelectorAll('img').forEach((img) => {
            img.removeAttribute('loading');
            img.removeAttribute('width');
            img.removeAttribute('height');
          });

          mainView.appendChild(slide);
        });

        // ── Initial caption ──────────────────────────────────────────────
        const getCaption = (i) => {
          const node = items[i] && items[i].querySelector('[data-gallery-item-caption]');
          return node ? node.innerHTML : '';
        };
        captionEl.innerHTML = getCaption(0);

        // ── Mark first item active ───────────────────────────────────────
        if (items[0]) items[0].classList.add('is-active');

        // ── Update aria-labels ───────────────────────────────────────────
        items.forEach((item, i) => {
          const labelEl = item.querySelector('.c-gallery-item__label');
          const label = labelEl ? labelEl.textContent.trim() : `Image ${i + 1}`;
          item.setAttribute('aria-label', `View: ${label}`);
        });

        // ── Wire click / keyboard handlers ───────────────────────────────
        items.forEach((item, i) => {
          item.addEventListener('click', () => activate(i));
          item.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              activate(i);
            }
          });
        });

        // ── Activate a slide ─────────────────────────────────────────────
        function activate(index) {
          if (index === activeIndex) return;

          // Cross-fade slides — CSS opacity transition handles animation
          mainView.querySelectorAll('.c-image-gallery__slide').forEach((slide, i) => {
            const active = i === index;
            slide.classList.toggle('is-active', active);
            slide.setAttribute('aria-hidden', active ? 'false' : 'true');
          });

          // Toggle active class on thumbnails
          items.forEach((item, i) => item.classList.toggle('is-active', i === index));

          // Fade caption out → update text → fade in
          captionEl.style.opacity = '0';
          setTimeout(() => {
            captionEl.innerHTML = getCaption(index);
            captionEl.style.opacity = '1';
          }, 220);

          activeIndex = index;
        }
      });
    },
  };
})(Drupal, once);
