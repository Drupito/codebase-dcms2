/**
 * @file
 * Pagination for the Tech Insights section.
 * Builds dots that scroll to each Tech Insights Slide atom in the track.
 */

(function (Drupal, once) {
  Drupal.behaviors.techInsights = {
    attach(context) {
      once('tech-insights', '.c-tech-insights', context).forEach((section) => {
        // YouTube click-to-play for slides with data-yt-src
        section.querySelectorAll('.js-ti-yt-wrap').forEach((wrap) => {
          const btn = wrap.querySelector('.js-ti-play-btn');
          if (!btn) return;
          btn.addEventListener('click', function () {
            const ytSrc = wrap.getAttribute('data-yt-src');
            if (!ytSrc) return;
            const thumbnail = wrap.querySelector('.c-tech-insights-slide__video-media');
            if (thumbnail) thumbnail.style.display = 'none';
            btn.style.display = 'none';
            const iframe = document.createElement('iframe');
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
            const cleanSrc = ytSrc.indexOf('?') > -1 ? ytSrc.split('?')[0] : ytSrc;
            iframe.src = cleanSrc + '?autoplay=1&enablejsapi=1&origin=' + encodeURIComponent(window.location.origin);
            wrap.appendChild(iframe);
          });

          // If the user clicks the thumbnail instead of the button, trigger the button click.
          const thumbnail = wrap.querySelector('.c-tech-insights-slide__video-media');
          if (thumbnail) {
            thumbnail.style.cursor = 'pointer';
            thumbnail.addEventListener('click', function () {
              btn.click();
            });
          }
        });

        const track = section.querySelector('.js-ti-track');
        const paginationEl = section.querySelector('.js-ti-pagination');
        if (!track || !paginationEl) return;

        const edgeEl = track.querySelector('.c-tech-insights__edge');

        const slides = Array.from(
          track.querySelectorAll('.c-tech-insights-slide')
        );

        if (slides.length <= 1) {
          paginationEl.style.display = 'none';
          return;
        }

        // Build pagination dots
        slides.forEach((_, i) => {
          const dot = document.createElement('button');
          dot.className =
            'c-tech-insights__dot' + (i === 0 ? ' is-active' : '');
          dot.setAttribute('role', 'tab');
          dot.setAttribute('aria-label', 'Slide ' + (i + 1));
          dot.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
          paginationEl.appendChild(dot);
          dot.addEventListener('click', () => goTo(i));
        });

        const dots = Array.from(
          paginationEl.querySelectorAll('.c-tech-insights__dot')
        );

        function goTo(index) {
          const edgeWidth = edgeEl ? edgeEl.offsetWidth : 0;
          const trackRect = track.getBoundingClientRect();
          const slideRect = slides[index].getBoundingClientRect();
          track.scrollTo({
            left: track.scrollLeft + slideRect.left - trackRect.left - edgeWidth,
            behavior: 'smooth',
          });
          updateDots(index);
        }

        function updateDots(activeIndex) {
          dots.forEach((d, i) => {
            const active = i === activeIndex;
            d.classList.toggle('is-active', active);
            d.setAttribute('aria-selected', active ? 'true' : 'false');
          });
        }

        // Sync active dot on scroll
        track.addEventListener(
          'scroll',
          () => {
            const trackRect = track.getBoundingClientRect();
            let closestIndex = 0;
            let closestDist = Infinity;
            const edgeWidth = edgeEl ? edgeEl.offsetWidth : 0;
            slides.forEach((slide, i) => {
              const dist = Math.abs(
                slide.getBoundingClientRect().left - trackRect.left - edgeWidth
              );
              if (dist < closestDist) {
                closestDist = dist;
                closestIndex = i;
              }
            });
            updateDots(closestIndex);
          },
          { passive: true }
        );
      });
    },
  };
})(Drupal, once);
