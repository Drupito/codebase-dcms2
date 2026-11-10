(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.anchorNavigation = {
    attach(context) {
      once('anchor-nav', '.js-anchor-nav', context).forEach((nav) => {
        const wrapper  = nav.closest('.js-anchor-nav-wrapper');
        const sentinel = wrapper ? wrapper.querySelector('.js-anchor-nav-sentinel') : null;
        const links    = nav.querySelectorAll('.js-anchor-nav-link');

        const getHeaderHeight = () => window.innerWidth < 1025 ? 80 : 144;

        // ── Sticky ──────────────────────────────────────────────────────────
        if (sentinel) {
          const stickyObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
              const h = getHeaderHeight();
              sentinel.style.top = `-${h}px`;
              if (!entry.isIntersecting && entry.boundingClientRect.top < 0) {
                nav.classList.add('is-stuck');
                nav.style.top = `${h}px`;
              } else {
                nav.classList.remove('is-stuck');
                nav.style.top = '';
              }
            });
          }, { root: null, threshold: [0, 1] });

          stickyObserver.observe(sentinel);
        }

        // ── Active state helper ──────────────────────────────────────────────
        const setActive = (target) => {
          links.forEach((link) => {
            const href       = link.getAttribute('href') || '';
            const anchor     = link.getAttribute('data-anchor') || '';
            let   linkPath   = href;
            try { linkPath = new URL(href, window.location.origin).pathname; } catch (e) {}

            const match = anchor === target || linkPath === target || href === target;
            link.classList.toggle('is-active', match);
          });
        };

        // Activate on load for path-based links.
        setActive(window.location.pathname);

        // ── Click handlers ───────────────────────────────────────────────────
        links.forEach((link) => {
          link.addEventListener('click', (e) => {
            const href = link.getAttribute('href') || '';

            // Resolve hash — handles both '#section' and '/path#section'.
            let hash = '';
            try {
              const resolved = new URL(href, window.location.origin);
              if (resolved.pathname === window.location.pathname || href.startsWith('#')) {
                hash = resolved.hash; // e.g. '#how-it-works'
              }
            } catch (_) {}

            if (hash) {
              e.preventDefault();
              const id     = hash.substring(1);
              const target = document.getElementById(id);
              if (target) {
                setActive(id);
                const offset = getHeaderHeight() + (nav.offsetHeight || 60);
                const top    = target.getBoundingClientRect().top + window.pageYOffset - offset + 1;
                window.scrollTo({ top, behavior: 'smooth' });
                history.pushState(null, '', hash);
              }
            } else {
              let path = href;
              try { path = new URL(href, window.location.origin).pathname; } catch (_) {}
              setActive(path);
            }
          });
        });

        // ── Scroll-spy (hash links only) ─────────────────────────────────────
        const getHash = (href) => {
          try {
            const resolved = new URL(href, window.location.origin);
            if (resolved.pathname === window.location.pathname || href.startsWith('#')) {
              return resolved.hash.substring(1);
            }
          } catch (_) {}
          return '';
        };

        const hasHash = Array.from(links).some((l) => getHash(l.getAttribute('href') || ''));
        if (!hasHash) return;

        const margin = window.innerWidth < 1025 ? -155 : -220;
        const spyObserver = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) setActive(entry.target.getAttribute('id'));
          });
        }, { root: null, rootMargin: `${margin}px 0px -70% 0px`, threshold: 0 });

        links.forEach((link) => {
          const id = getHash(link.getAttribute('href') || '');
          if (id) {
            const el = document.getElementById(id);
            if (el) spyObserver.observe(el);
          }
        });
      });
    },
  };
})(Drupal, once);
