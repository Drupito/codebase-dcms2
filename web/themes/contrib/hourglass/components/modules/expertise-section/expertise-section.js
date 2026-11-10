(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.expertiseSection = {
    attach(context) {
      once('expertise-section', '.c-expertise-section__cards', context).forEach((track) => {
        let isDown = false;
        let startX = 0;
        let scrollLeft = 0;

        track.addEventListener('mousedown', (e) => {
          isDown = true;
          track.classList.add('is-dragging');
          startX = e.pageX - track.offsetLeft;
          scrollLeft = track.scrollLeft;
        });

        track.addEventListener('mouseleave', () => {
          isDown = false;
          track.classList.remove('is-dragging');
        });

        track.addEventListener('mouseup', () => {
          isDown = false;
          track.classList.remove('is-dragging');
        });

        track.addEventListener('mousemove', (e) => {
          if (!isDown) return;
          e.preventDefault();
          const x = e.pageX - track.offsetLeft;
          const walk = (x - startX) * 1.5;
          track.scrollLeft = scrollLeft - walk;
        });
      });
    },
  };
})(Drupal, once);
