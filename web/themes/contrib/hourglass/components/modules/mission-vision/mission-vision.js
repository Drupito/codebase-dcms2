/**
 * @file
 * Sticky stacking animation for the Mission Vision section.
 * Mirrors the MMI mission-vision implementation.
 */

(function (Drupal, once) {
  Drupal.behaviors.missionVision = {
    attach(context) {
      once('mission-vision', '[data-mission-vision="container"]', context).forEach((container) => {
        this.initStickyEnforcer(container);
        this.initCardIndexing(container);
      });
    },

    initCardIndexing(container) {
      const cards = container.querySelectorAll('[data-mission-vision="card"]');
      cards.forEach((card, index) => {
        card.style.setProperty('--card-index', index + 1);
      });
    },

    initStickyEnforcer(container) {
      let parent = container.parentElement;
      while (parent && parent !== document.body) {
        const style = window.getComputedStyle(parent);

        if (style.overflow === 'hidden' || style.overflow === 'clip') {
          parent.style.overflow = 'visible';
        }
        if (style.overflowY === 'hidden' || style.overflowY === 'clip') {
          parent.style.overflowY = 'visible';
        }
        // overflow-x:hidden creates a scroll container that breaks position:sticky
        // Replace with clip — clips visually but does NOT create a scroll container
        if (style.overflowX === 'hidden') {
          parent.style.overflowX = 'clip';
        }

        parent = parent.parentElement;
      }
    },
  };
})(Drupal, once);
