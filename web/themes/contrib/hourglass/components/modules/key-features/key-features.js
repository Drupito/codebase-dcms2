/**
 * @file
 * Custom carousel for the Key Features section — no Splide dependency.
 *
 * Handles two cases:
 *  1. Slides inside a .c-key-features wrapper (Twig-rendered).
 *  2. Bare .c-key-feature-slide atoms dropped outside a wrapper (Canvas legacy).
 *
 * A MutationObserver ensures that duplicating a slide in the Canvas editor
 * triggers an automatic slider rebuild without a page reload.
 */

(function (Drupal, once) {
  'use strict';

  // ── Helpers ────────────────────────────────────────────────────────────────

  function getAllSlides(section) {
    return Array.from(section.querySelectorAll('.c-key-feature-slide'));
  }

  /**
   * Build (or rebuild) the custom slider for a .c-key-features section.
   * Destroys any previously mounted instance stored on the element.
   */
  function buildSlider(section) {
    const slidesWrapper = section.querySelector('.js-kf-slides');
    const paginationEl  = section.querySelector('.js-kf-pagination');
    if (!slidesWrapper) return;

    // Tear down any previous slider instance.
    if (section._kfSlider) {
      section._kfSlider.destroy();
      section._kfSlider = null;
    }

    const slides = getAllSlides(section);

    // Single slide — show it without any wrapper or pagination.
    if (slides.length <= 1) {
      if (paginationEl) paginationEl.innerHTML = '';
      slidesWrapper.innerHTML = '';
      if (slides[0]) slidesWrapper.appendChild(slides[0]);
      return;
    }

    // ── Build DOM ────────────────────────────────────────────────────────────
    // Structure: .c-key-features__slider > .c-key-features__track > [slides]
    const sliderEl = document.createElement('div');
    sliderEl.className = 'c-key-features__slider';

    const track = document.createElement('div');
    track.className = 'c-key-features__track';
    track.style.transition = 'transform 0.4s ease';

    slides.forEach((slide) => track.appendChild(slide));

    sliderEl.appendChild(track);
    slidesWrapper.innerHTML = '';
    slidesWrapper.appendChild(sliderEl);

    // ── State ────────────────────────────────────────────────────────────────
    let currentIndex = 0;

    // ── Pagination ───────────────────────────────────────────────────────────
    // Reuse existing CSS that targets .splide__pagination / .splide__pagination__page.
    function buildPagination() {
      if (!paginationEl) return;
      paginationEl.innerHTML = '';

      const list = document.createElement('ul');
      list.className = 'splide__pagination';

      slides.forEach((_, i) => {
        const li  = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'splide__pagination__page' + (i === 0 ? ' is-active' : '');
        btn.setAttribute('aria-label', `Go to slide ${i + 1}`);
        btn.addEventListener('click', () => goTo(i));
        li.appendChild(btn);
        list.appendChild(li);
      });

      paginationEl.appendChild(list);
    }

    function updatePagination() {
      if (!paginationEl) return;
      paginationEl.querySelectorAll('.splide__pagination__page').forEach((dot, i) => {
        dot.classList.toggle('is-active', i === currentIndex);
      });
    }

    function goTo(index) {
      currentIndex = Math.max(0, Math.min(index, slides.length - 1));
      track.style.transform = `translateX(-${currentIndex * 100}%)`;
      updatePagination();
    }

    buildPagination();

    // ── Drag / swipe (Pointer Events) ────────────────────────────────────────
    let startX    = 0;
    let dragDelta = 0;
    let isDragging = false;

    function onPointerDown(e) {
      startX    = e.clientX;
      dragDelta = 0;
      isDragging = true;
      track.classList.add('is-dragging');
      track.style.transition = 'none';
      try { sliderEl.setPointerCapture(e.pointerId); } catch (_) {}
    }

    function onPointerMove(e) {
      if (!isDragging) return;
      dragDelta = e.clientX - startX;
    }

    function onPointerUp() {
      if (!isDragging) return;
      isDragging = false;
      track.classList.remove('is-dragging');
      track.style.transition = 'transform 0.4s ease';
      if (dragDelta < -50 && currentIndex < slides.length - 1) {
        goTo(currentIndex + 1);
      } else if (dragDelta > 50 && currentIndex > 0) {
        goTo(currentIndex - 1);
      } else {
        track.style.transform = `translateX(-${currentIndex * 100}%)`;
      }
    }

    sliderEl.addEventListener('pointerdown', onPointerDown);
    sliderEl.addEventListener('pointermove', onPointerMove);
    sliderEl.addEventListener('pointerup',   onPointerUp);
    sliderEl.addEventListener('pointercancel', onPointerUp);

    section._kfSlider = {
      destroy() {
        sliderEl.removeEventListener('pointerdown', onPointerDown);
        sliderEl.removeEventListener('pointermove', onPointerMove);
        sliderEl.removeEventListener('pointerup',   onPointerUp);
        sliderEl.removeEventListener('pointercancel', onPointerUp);
      },
    };
  }

  /**
   * Attach a MutationObserver so Canvas slide duplication triggers a rebuild.
   */
  function watchSection(section) {
    if (section._kfObserver) return;

    const observer = new MutationObserver(() => {
      const hasOrphan = getAllSlides(section).some(
        (s) => !s.closest('.c-key-features__track')
      );
      if (hasOrphan) buildSlider(section);
    });

    observer.observe(section, { childList: true, subtree: true });
    section._kfObserver = observer;
  }

  // ── Drupal behavior ────────────────────────────────────────────────────────

  Drupal.behaviors.keyFeatures = {
    attach(context) {
      // ── Case 1: bare slides outside any .c-key-features wrapper ─────────
      // (Canvas dropped a slide atom at page root level.)
      if (context && typeof context.querySelectorAll === 'function') {
        const uncontained = Array.from(
          context.querySelectorAll('.c-key-feature-slide')
        ).filter((slide) => !slide.closest('.c-key-features'));

        if (uncontained.length > 0) {
          const fresh = once('key-features-wrap', uncontained);
          if (fresh.length > 0) {
            const groups = [];
            let group = [];
            fresh.forEach((slide) => {
              if (!group.length || slide.previousElementSibling === group[group.length - 1]) {
                group.push(slide);
              } else {
                groups.push(group);
                group = [slide];
              }
            });
            if (group.length) groups.push(group);

            groups.forEach((g) => {
              if (g.length <= 1) return;
              const parent = g[0].parentNode;
              const section = document.createElement('section');
              section.className = 'c-key-features c-key-features--dynamic';

              const wrapper = document.createElement('div');
              wrapper.className = 'c-key-features__slides js-kf-slides';

              const pagination = document.createElement('div');
              pagination.className = 'c-key-features__pagination js-kf-pagination';
              pagination.setAttribute('role', 'tablist');
              pagination.setAttribute('aria-label', 'Key feature slides');

              section.appendChild(wrapper);
              section.appendChild(pagination);
              parent.insertBefore(section, g[0]);
              g.forEach((s) => wrapper.appendChild(s));

              Drupal.attachBehaviors(section);
            });
          }
        }
      }

      // ── Case 2: properly wrapped .c-key-features sections ───────────────
      const root = (context && typeof context.querySelectorAll === 'function')
        ? context
        : document;

      // Collect sections: descendants of context + context itself if it IS
      // a .c-key-features element (Canvas passes the component root as context).
      const sections = Array.from(root.querySelectorAll('.c-key-features'));
      if (root.matches && root.matches('.c-key-features')) {
        sections.unshift(root);
      }
      // Also handle context = a .c-key-feature-slide — walk up to parent section.
      if (root.matches && root.matches('.c-key-feature-slide')) {
        const parent = root.closest('.c-key-features');
        if (parent && !sections.includes(parent)) sections.unshift(parent);
      }

      sections.forEach((section) => {
        // Rebuild if not yet initialised OR if a new slide is outside the track.
        const hasOrphan = getAllSlides(section).some(
          (s) => !s.closest('.c-key-features__track')
        );
        if (!section.dataset.kfInit || hasOrphan) {
          section.dataset.kfInit = '1';
          buildSlider(section);
        }
        // Always re-attach observer (safe: watchSection guards against duplication).
        watchSection(section);
      });
    },
  };
})(Drupal, once);
