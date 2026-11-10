/**
 * @file
 * History Timeline — drag-to-scroll horizontal milestone track.
 *
 * Reads History Timeline Slide atoms from the flat .js-ht-source slot
 * (Canvas-accessible), builds the scrollable list in the hidden viewport,
 * then enables drag-to-scroll interaction.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.historyTimeline = {
    attach(context) {
      once('history-timeline', '.c-history-timeline', context).forEach((section) => {
        const source = section.querySelector('.js-ht-source');
        const viewport = section.querySelector('.js-ht-viewport');
        const slider = section.querySelector('.js-ht-slider');
        const list = section.querySelector('.js-ht-list');

        if (!source || !viewport || !slider || !list) return;

        const slides = Array.from(source.children);
        if (!slides.length) return;

        // Opening spacer.
        const startSpacer = document.createElement('li');
        startSpacer.className = 'c-history-timeline__spacer';
        list.appendChild(startSpacer);

        // Clone each slide atom into the scrollable list.
        slides.forEach((slide) => {
          const li = document.createElement('li');
          li.className = 'c-history-timeline__slide-wrapper';
          li.appendChild(slide.cloneNode(true));
          list.appendChild(li);
        });

        // Closing spacer.
        const endSpacer = document.createElement('li');
        endSpacer.className = 'c-history-timeline__spacer';
        list.appendChild(endSpacer);

        // Hide source, reveal viewport.
        source.style.cssText =
          'position:absolute;width:1px;height:1px;overflow:hidden;' +
          'clip:rect(0,0,0,0);white-space:nowrap;pointer-events:none';
        viewport.removeAttribute('aria-hidden');

        // Drag-to-scroll.
        let isDown = false;
        let startX;
        let scrollLeft;

        slider.addEventListener('mousedown', (e) => {
          isDown = true;
          slider.classList.add('is-dragging');
          startX = e.pageX - slider.offsetLeft;
          scrollLeft = slider.scrollLeft;
        });

        slider.addEventListener('mouseleave', () => {
          isDown = false;
          slider.classList.remove('is-dragging');
        });

        slider.addEventListener('mouseup', () => {
          isDown = false;
          slider.classList.remove('is-dragging');
        });

        slider.addEventListener('mousemove', (e) => {
          if (!isDown) return;
          e.preventDefault();
          const x = e.pageX - slider.offsetLeft;
          const walk = (x - startX) * 2;
          slider.scrollLeft = scrollLeft - walk;
        });
      });
    },
  };
})(Drupal, once);
