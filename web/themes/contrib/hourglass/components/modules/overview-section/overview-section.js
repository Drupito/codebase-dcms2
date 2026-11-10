/**
 * @file
 * GSAP ScrollTrigger animation for the Overview Section component.
 *
 * Enabled per-block via data-show-animation="true".
 * On scroll: circular image expands to fill the viewport, drifts toward the
 * centre of the next section, then fades out; the feature panel slides left.
 */

(function (Drupal, once, gsap, ScrollTrigger) {
  Drupal.behaviors.corporateOverviewSectionGSAP = {
    attach(context) {
      const blocks = once('corporate-overview-section-gsap', '.c-overview-section', context);
      if (!blocks.length || !gsap) return;

      gsap.registerPlugin(ScrollTrigger);

      blocks.forEach((block) => {
        if (block.getAttribute('data-show-animation') !== 'true') return;

        const mediaEl = block.querySelector('.c-overview-section__image-wrap');
        if (!mediaEl) return;

        // Walk up the DOM to find the block's true sibling context.
        let sectionWrapper = block;
        while (sectionWrapper && sectionWrapper.parentElement) {
          const parent = sectionWrapper.parentElement;
          const siblings = Array.from(parent.children);
          const idx = siblings.indexOf(sectionWrapper);
          if (siblings.length > 1 && idx < siblings.length - 1) break;
          sectionWrapper = parent;
        }
        const nextSection = sectionWrapper ? sectionWrapper.nextElementSibling : null;

        function getFullScale() {
          const mw = mediaEl.offsetWidth || 500;
          const mh = mediaEl.offsetHeight || 500;
          return Math.max(window.innerWidth / mw, window.innerHeight / mh) * 1.3;
        }

        const tl = gsap.timeline({
          scrollTrigger: {
            trigger: block,
            start: window.innerWidth < 992 ? 'top 80%' : '100% bottom',
            endTrigger: nextSection || block,
            end: nextSection ? '-100% -100%' : '+=100%',
            scrub: 1,
            invalidateOnRefresh: true,
          },
        });

        // Expand + move toward next section centre simultaneously.
        tl.to(mediaEl, {
          y: () => {
            if (nextSection) {
              const mr = mediaEl.getBoundingClientRect();
              const nr = nextSection.getBoundingClientRect();
              return (nr.top + nr.height / 2) - (mr.top + mr.height / 2);
            }
            return window.innerHeight;
          },
          x: () => {
            const mr = mediaEl.getBoundingClientRect();
            return (window.innerWidth / 2 - (mr.left + mr.width / 2)) * 0.7;
          },
          scale: () => getFullScale(),
          duration: 1.0,
          ease: 'none',
        });

        // Continue drifting after expansion.
        tl.to(mediaEl, {
          y: '+=200',
          x: '+=50',
          duration: 1.0,
          ease: 'sine.out',
        }, 1.0);

        // Fade out overlapping the expansion.
        tl.to(mediaEl, {
          opacity: 0,
          duration: 1.2,
          ease: 'sine.inOut',
        }, 0.8);

        // Slide the feature panel to the left on desktop.
        const featuresEl = block.querySelector('.c-overview-section__features');
        if (featuresEl && window.innerWidth >= 992) {
          tl.to(featuresEl, {
            x: -200,
            opacity: 0,
            duration: 0.8,
            ease: 'power1.inOut',
          }, 0);
        }
      });
    },
  };
})(Drupal, once, window.gsap, window.ScrollTrigger);
