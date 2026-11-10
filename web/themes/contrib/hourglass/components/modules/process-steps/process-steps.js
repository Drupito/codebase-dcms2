/**
 * @file
 * Process Steps — rAF-driven progress line with segment-by-segment reveal.
 *
 * Mirrors the MMI Process Timeline animation:
 *   - Progress line grows pixel-by-pixel via requestAnimationFrame.
 *   - Left tail extends from viewport edge to first circle.
 *   - Right tail extends from last circle to viewport edge.
 *   - Gradient leading edge fades out the advancing front.
 *   - Steps gain `.is-active` (border glow) as the line passes through them.
 *   - Animation loops continuously while the section is in view.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.processSteps = {
    attach(context) {
      once('process-steps', '.js-process-steps', context).forEach((section) => {
        const steps = Array.from(section.querySelectorAll('[data-process-step]'));
        if (!steps.length) return;

        const totalSteps = steps.length;

        let animationStart = 0;
        let animationFrame = null;
        let isIntersecting = false;

        let segmentLengths = [];
        let totalDistance = 0;
        let totalDuration = 0;
        let lastWindowWidth = 0;

        const SPEED = 100; // px per second
        const ORANGE = '#f76707';
        const FADING = 'linear-gradient(90deg, #f76707 0%, #f76707 37%, rgba(255,255,255,0) 100%)';

        /**
         * Measure the vertical center of a step's circle, relative to the step top.
         * Sets --circle-top on each step so the progress segments align correctly.
         */
        const measureCircleTops = () => {
          steps.forEach((step) => {
            const circle = step.querySelector('.c-process-step__circle');
            if (!circle) return;
            const stepRect = step.getBoundingClientRect();
            const circleRect = circle.getBoundingClientRect();
            const top = Math.round(circleRect.top - stepRect.top + circleRect.height / 2 - 1);
            step.style.setProperty('--circle-top', `${top}px`);
          });
        };

        /**
         * Calculate per-segment pixel lengths and cache them.
         * Re-runs only when the viewport width changes.
         */
        const calculateSegments = () => {
          const windowWidth = window.innerWidth;
          if (windowWidth === lastWindowWidth && segmentLengths.length > 0) return;
          lastWindowWidth = windowWidth;

          if (windowWidth <= 768) {
            segmentLengths = [];
            return;
          }

          const innerContainer = section.querySelector('.c-process-steps__inner');
          if (!innerContainer) return;

          measureCircleTops();

          const containerWidth = innerContainer.offsetWidth;
          const stepWidths = steps.map((s) => s.offsetWidth);
          const tailLength = (windowWidth - containerWidth) / 2 + stepWidths[0] / 2;

          section.style.setProperty('--tail-length', `${tailLength}px`);

          segmentLengths = [tailLength];
          for (let i = 0; i < totalSteps - 1; i++) {
            segmentLengths.push(stepWidths[i] / 2 + stepWidths[i + 1] / 2);
          }
          // Right tail: viewport edge to last circle
          const lastTail = (windowWidth - containerWidth) / 2 + stepWidths[totalSteps - 1] / 2;
          segmentLengths.push(lastTail);

          totalDistance = segmentLengths.reduce((a, b) => a + b, 0);
          totalDuration = (totalDistance / SPEED) * 1000;
        };

        /**
         * rAF callback — advances the progress line and toggles is-active on steps.
         */
        const updateSteps = (timestamp) => {
          if (!isIntersecting) return;
          if (!animationStart) animationStart = timestamp;

          calculateSegments();

          if (!segmentLengths.length) {
            animationFrame = requestAnimationFrame(updateSteps);
            return;
          }

          const elapsed = (timestamp - animationStart) % totalDuration;
          const progressPixels = (elapsed / 1000) * SPEED;

          let activeIndex = -1;
          let currentDist = 0;

          steps.forEach((step, i) => {
            const beforeSeg = step.querySelector('[data-progress="before"]');
            const afterSeg = step.querySelector('[data-progress="after"]');

            if (i === 0 && beforeSeg && afterSeg) {
              // ── Left tail (before first circle) ──────────────────────────
              const len0 = segmentLengths[0];
              const seg0 = Math.max(0, Math.min(len0, progressPixels - currentDist));
              beforeSeg.style.width = `${seg0}px`;
              beforeSeg.style.background = seg0 >= len0 ? ORANGE : FADING;
              if (seg0 >= len0) activeIndex = Math.max(activeIndex, 0);
              currentDist += len0;

              // ── First connector (circle 0 → circle 1) ─────────────────
              const len1 = segmentLengths[1];
              const seg1 = Math.max(0, Math.min(len1, progressPixels - currentDist));
              afterSeg.style.width = `${seg1}px`;
              afterSeg.style.background = seg1 >= len1 ? ORANGE : FADING;
              if (seg1 >= len1) activeIndex = Math.max(activeIndex, 1);
              currentDist += len1;

            } else if (afterSeg) {
              const segIndex = i + 1;
              const len = segmentLengths[segIndex];
              const segSize = Math.max(0, Math.min(len, progressPixels - currentDist));
              afterSeg.style.width = `${segSize}px`;
              afterSeg.style.background = segSize >= len ? ORANGE : FADING;

              if (i < totalSteps - 1 && segSize >= len) {
                activeIndex = Math.max(activeIndex, i + 1);
              }
              if (i === totalSteps - 1 && progressPixels >= currentDist) {
                activeIndex = Math.max(activeIndex, i);
              }
              currentDist += len;
            }
          });

          steps.forEach((step, i) => {
            const shouldBeActive = i <= activeIndex;
            if (step.classList.contains('is-active') !== shouldBeActive) {
              step.classList.toggle('is-active', shouldBeActive);
            }
          });

          animationFrame = requestAnimationFrame(updateSteps);
        };

        const startAnimation = () => {
          animationStart = 0;
          animationFrame = requestAnimationFrame(updateSteps);
        };

        const stopAnimation = () => {
          if (animationFrame) cancelAnimationFrame(animationFrame);
        };

        const observer = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              isIntersecting = entry.isIntersecting;
              if (isIntersecting) startAnimation();
              else stopAnimation();
            });
          },
          { threshold: 0.2 }
        );

        observer.observe(section);
      });
    },
  };
})(Drupal, once);
