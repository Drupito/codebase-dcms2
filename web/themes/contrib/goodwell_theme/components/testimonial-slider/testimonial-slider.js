/**
 * @file testimonial-slider.js
 * Testimonial slider SDC: dot navigation, auto-advance, keyboard support.
 */
(function () {

  function getSlides(slider) {
    return Array.from(slider.querySelectorAll('[data-testimonial-slide]'));
  }

  function getDots(slider) {
    return Array.from(slider.querySelectorAll('[data-testimonial-dot]'));
  }

  function showSlide(slider, index) {
    var slides = getSlides(slider);
    var dots   = getDots(slider);
    if (!slides.length) return;

    // Clamp index
    index = (index + slides.length) % slides.length;

    slides.forEach(function (slide, i) {
      if (i === index) {
        slide.removeAttribute('hidden');
      } else {
        slide.setAttribute('hidden', '');
      }
    });

    dots.forEach(function (dot, i) {
      if (i === index) {
        dot.classList.add('dst-testimonial-slider__dot--active');
        dot.setAttribute('aria-current', 'true');
      } else {
        dot.classList.remove('dst-testimonial-slider__dot--active');
        dot.removeAttribute('aria-current');
      }
    });

    slider._currentIndex = index;
  }

  function startAutoplay(slider) {
    var interval = parseInt(slider.dataset.autoplay, 10);
    if (!interval || isNaN(interval)) return;

    slider._autoplayTimer = setInterval(function () {
      var slides = getSlides(slider);
      showSlide(slider, (slider._currentIndex + 1) % slides.length);
    }, interval);
  }

  function stopAutoplay(slider) {
    if (slider._autoplayTimer) {
      clearInterval(slider._autoplayTimer);
      slider._autoplayTimer = null;
    }
  }

  function bindSlider(slider) {
    if (slider.dataset.testimonialInitialized === 'true') return;
    slider.dataset.testimonialInitialized = 'true';
    slider._currentIndex = 0;

    // Dot clicks
    getDots(slider).forEach(function (dot) {
      dot.addEventListener('click', function () {
        stopAutoplay(slider);
        showSlide(slider, parseInt(dot.dataset.testimonialDot, 10));
        startAutoplay(slider);
      });
    });

    // Keyboard left/right when slider or a child is focused
    slider.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        stopAutoplay(slider);
        var direction = e.key === 'ArrowRight' ? 1 : -1;
        showSlide(slider, slider._currentIndex + direction);
        startAutoplay(slider);
      }
    });

    // Pause on hover / focus-within
    slider.addEventListener('mouseenter', function () { stopAutoplay(slider); });
    slider.addEventListener('mouseleave', function () { startAutoplay(slider); });
    slider.addEventListener('focusin',    function () { stopAutoplay(slider); });
    slider.addEventListener('focusout',   function () { startAutoplay(slider); });

    startAutoplay(slider);
  }

  function init(context) {
    var sliders = (context || document).querySelectorAll('[data-testimonial-slider]');
    sliders.forEach(bindSlider);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }

  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.testimonialSlider = {
      attach: function (context) {
        init(context);
      },
    };
  }

}());
