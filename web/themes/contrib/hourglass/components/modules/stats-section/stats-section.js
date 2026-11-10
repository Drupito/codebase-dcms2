/**
 * @file
 * Stats Section — static grid for ≤3 cards, Splide loop slider for 4+.
 *
 * Canvas editors work in the flat .js-ss-source slot. On the front-end, JS
 * either leaves the source as a grid or clones items into a Splide carousel.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.statsSection = {
    attach(context) {
      once('stats-section', '.c-stats-section', context).forEach((section) => {
        const source = section.querySelector('.js-ss-source');
        const viewport = section.querySelector('.js-ss-viewport');
        const splideEl = section.querySelector('.js-ss-splide');
        const splideList = section.querySelector('.js-ss-splide .splide__list');

        if (!source) return;

        const items = Array.from(source.children);
        if (!items.length) return;

        if (items.length <= 3) {
          // Static grid — add modifier so CSS gives equal columns.
          source.classList.add('is-grid');
          // Viewport not needed.
          if (viewport) viewport.remove();
          return;
        }

        // 4+ cards — initialize Splide slider.
        if (!viewport || !splideEl || !splideList) return;

        if (typeof Splide === 'undefined') {
          console.error('Splide is not loaded.');
          return;
        }

        items.forEach((item) => {
          const li = document.createElement('li');
          li.className = 'splide__slide';
          li.appendChild(item.cloneNode(true));
          splideList.appendChild(li);
        });

        // Hide source, reveal viewport.
        source.style.cssText =
          'position:absolute;width:1px;height:1px;overflow:hidden;' +
          'clip:rect(0,0,0,0);white-space:nowrap;pointer-events:none';
        viewport.removeAttribute('aria-hidden');

        const splide = new Splide(splideEl, {
          type: 'loop',
          perPage: 3,
          perMove: 1,
          gap: '20px',
          padding: { left: '120px', right: '120px' },
          arrows: true,
          pagination: true,
          breakpoints: {
            1024: { perPage: 2, padding: { left: '60px', right: '60px' } },
            768:  { perPage: 1, padding: { left: '24px', right: '24px' } },
          },
        });

        splide.mount();
      });
    },
  };
})(Drupal, once);
