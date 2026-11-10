/**
 * @file
 * JS for the Media component.
 */

(function (Drupal, once) {
  /**
   * Behavior for the Media component.
   *
   * Handles video autoplay, looping, and muted states for both
   * native <video> elements and oEmbed iframes (YouTube).
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.corporateMedia = {
    /**
     * @param {HTMLElement|Document} context
     *   The DOM element to attach the behavior to.
     */
    attach: function (context) {
      const elements = once('corporate-media', '.c-base-media', context);

      elements.forEach((el) => {
        // YouTube poster → click-to-play (standalone, without video-feature wrapper)
        const ytEmbed = el.querySelector('.js-yt-embed');
        if (ytEmbed) {
          if (el.closest('.c-video-feature')) {
            return;
          }
          const poster = ytEmbed.querySelector('.js-yt-poster');
          if (poster) {
            poster.style.cursor = 'pointer';
            poster.addEventListener('click', function () {
              const ytSrc = ytEmbed.getAttribute('data-yt-src');
              poster.style.display = 'none';
              const iframe = document.createElement('iframe');
              iframe.className = 'c-base-media__iframe';
              iframe.setAttribute('frameborder', '0');
              iframe.setAttribute('allowfullscreen', '');
              iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
              const cleanSrc = ytSrc.indexOf('?') > -1 ? ytSrc.split('?')[0] : ytSrc;
              iframe.src = cleanSrc + '?autoplay=1&enablejsapi=1&origin=' + encodeURIComponent(window.location.origin);
              ytEmbed.appendChild(iframe);
            });
          }
          return;
        }

        const video = el.querySelector('video');
        if (!video) {
          return;
        }

        const autoplay = el.getAttribute('data-autoplay') === 'true';
        const loop = el.getAttribute('data-loop') === 'true';
        const muted = el.getAttribute('data-muted') === 'true';
        const controls = el.getAttribute('data-controls') === 'true';

        if (video) {
          video.loop = loop;
          
          if (controls) {
            video.controls = true;
            video.setAttribute('controls', 'controls');
          } else {
            video.controls = false;
            video.removeAttribute('controls');
          }

          if (autoplay) {
            video.muted = true;
            video.setAttribute('muted', '');
            video.autoplay = true;
            video.setAttribute('autoplay', '');

            const playPromise = video.play();
            if (playPromise !== undefined) {
              playPromise.catch((error) => {
                console.warn('Autoplay was prevented:', error);
              });
            }
          } else {
            video.muted = muted;
            if (muted) {
              video.setAttribute('muted', '');
            } else {
              video.removeAttribute('muted');
            }
          }
        } else {
          const iframe = el.querySelector('iframe');
          if (iframe && autoplay) {
            try {
              const srcUrl = new URL(iframe.src);
              const targetUrlStr = srcUrl.searchParams.get('url');
              if (targetUrlStr) {
                const targetUrl = new URL(targetUrlStr);
                let newIframeSrc = '';
                
                if (targetUrl.hostname.includes('youtube.com') || targetUrl.hostname.includes('youtu.be')) {
                  const videoId = targetUrl.searchParams.get('v') || targetUrl.pathname.split('/').pop();
                  newIframeSrc = `https://www.youtube.com/embed/${videoId}?autoplay=1&mute=1&playsinline=1&enablejsapi=1&origin=${encodeURIComponent(window.location.origin)}`;
                  if (loop) {
                    newIframeSrc += `&loop=1&playlist=${videoId}`;
                  }
                  if (!controls) {
                    newIframeSrc += `&controls=0`;
                  }
                }


                if (newIframeSrc) {
                  const newIframe = document.createElement('iframe');
                  newIframe.src = newIframeSrc;
                  newIframe.className = iframe.className;
                  newIframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
                  newIframe.setAttribute('allowfullscreen', 'true');
                  newIframe.setAttribute('title', iframe.getAttribute('title') || 'Video');
                  newIframe.style.cssText = iframe.style.cssText;
                  
                  iframe.parentNode.replaceChild(newIframe, iframe);
                }
              }
            } catch (e) {
              console.error('Failed to parse oembed iframe URL for autoplay', e);
            }
          }
        }
      });
    },
  };
})(Drupal, once);
