/**
 * @file
 * Video Feature — play button toggles inline video playback.
 * Handles mp4 <video> elements and YouTube iframes (thumbnail + no-thumbnail).
 */

(function (Drupal, once) {
  Drupal.behaviors.videoFeature = {
    attach: function (context) {
      once('video-feature', '.c-video-feature', context).forEach(function (section) {
        var mediaWrap = section.querySelector('.js-vf-media');
        var playBtn = section.querySelector('.js-vf-play');
        var thumbnail = section.querySelector('.js-vf-thumbnail');

        if (!playBtn || !mediaWrap) return;

        var video = thumbnail ? thumbnail.querySelector('video') : null;
        var ytEmbed = thumbnail ? thumbnail.querySelector('.js-yt-embed') : null;

        // Strip native controls — our button is the only control.
        if (video) {
          video.removeAttribute('controls');
        }

        // Transparent overlay: sits above the thumbnail (z-index 1) but below the
        // play button (z-index 2). Blocks all direct clicks on the video element,
        // which can bypass CSS pointer-events via the browser's native shadow DOM.
        var overlay = document.createElement('div');
        overlay.className = 'c-video-feature__overlay';
        mediaWrap.appendChild(overlay);

        // Clicking the overlay toggles video playback.
        overlay.addEventListener('click', function (e) {
          e.stopPropagation();
          playBtn.click();
        });

        playBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          if (ytEmbed) {
            var ytSrc = ytEmbed.getAttribute('data-yt-src');
            var ytPoster = ytEmbed.querySelector('.js-yt-poster');
            var iframe = ytEmbed.querySelector('iframe');
            var isPlaying = mediaWrap.classList.contains('is-playing');

            if (isPlaying && iframe) {
              // Pause: stop audio/video via postMessage
              iframe.contentWindow.postMessage(
                JSON.stringify({ event: 'command', func: 'pauseVideo', args: [] }),
                '*'
              );
              mediaWrap.classList.remove('is-playing');
            } else if (ytPoster && !iframe) {
              // Thumbnail mode: hide poster, inject autoplay iframe
              ytPoster.style.display = 'none';
              var newIframe = document.createElement('iframe');
              newIframe.className = 'c-base-media__iframe';
              newIframe.setAttribute('frameborder', '0');
              newIframe.setAttribute('allowfullscreen', '');
              newIframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
              var cleanSrc = ytSrc.indexOf('?') > -1 ? ytSrc.split('?')[0] : ytSrc;
              newIframe.src = cleanSrc + '?autoplay=1&enablejsapi=1&origin=' + encodeURIComponent(window.location.origin);
              ytEmbed.appendChild(newIframe);
              mediaWrap.classList.add('is-playing');
            } else if (iframe) {
              // No thumbnail: iframe already in DOM, play via YouTube postMessage API
              iframe.contentWindow.postMessage(
                JSON.stringify({ event: 'command', func: 'playVideo', args: [] }),
                '*'
              );
              mediaWrap.classList.add('is-playing');
            }
          } else if (video) {
            if (video.paused) {
              video.play();
            } else {
              video.pause();
            }
          }
        });

        if (video) {
          video.addEventListener('play', function () {
            mediaWrap.classList.add('is-playing');
          });
          video.addEventListener('pause', function () {
            if (!video.ended) {
              mediaWrap.classList.remove('is-playing');
            }
          });
          video.addEventListener('ended', function () {
            mediaWrap.classList.remove('is-playing');
          });
        }
      });
    },
  };
})(Drupal, once);
