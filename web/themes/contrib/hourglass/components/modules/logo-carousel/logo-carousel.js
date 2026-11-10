/**
 * @file
 * Logo Carousel — Splide.js with AutoScroll, mirroring MMI implementation.
 *
 * Reads Logo Item atoms from the flat .js-lc-source slot (Canvas-accessible),
 * builds the Splide slide list in the hidden viewport, then mounts Splide
 * with AutoScroll at speed 1 — identical to the MMI project.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.logoCarousel = {
    attach(context) {
      once('logo-carousel', '.c-logo-carousel', context).forEach((section) => {
        const source = section.querySelector('.js-lc-source');
        const viewport = section.querySelector('.js-lc-viewport');
        const splideEl = section.querySelector('.js-lc-splide');
        const splideList = section.querySelector('.js-lc-splide .splide__list');

        if (!source || !viewport || !splideEl || !splideList) return;

        const items = Array.from(source.children);
        if (!items.length) return;

        if (typeof Splide === 'undefined') {
          console.error('Splide is not loaded.');
          return;
        }

        // Wrap each logo item in a splide__slide <li>.
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

        // Mount Splide — same config as MMI logo-carousel.
        const splide = new Splide(splideEl, {
          type: 'loop',
          drag: 'free',
          autoWidth: true,
          gap: '80px',
          arrows: false,
          pagination: false,
          clones: 10,
          autoScroll: {
            speed: 1,
            pauseOnHover: true,
            pauseOnFocus: false,
            autoStart: true,
          },
        });

        splide.mount(window.splide.Extensions);
      });
    },
  };
})(Drupal, once);
