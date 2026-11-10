/**
 * @file
 * Prev/next navigation for the Offerings Slider.
 */

(function (Drupal, once) {
  Drupal.behaviors.offeringsSlider = {
    attach(context) {
      once('offerings-slider', '.c-offerings-slider', context).forEach((slider) => {
        const track = slider.querySelector('.js-offerings-track');
        const prevBtn = slider.querySelector('.js-offerings-prev');
        const nextBtn = slider.querySelector('.js-offerings-next');
        if (!track) return;

        const scrollAmount = () => {
          const card = track.querySelector('.c-offering-card');
          return card ? card.offsetWidth + 12 : 580;
        };

        if (prevBtn) {
          prevBtn.addEventListener('click', () => {
            track.scrollBy({ left: -scrollAmount(), behavior: 'smooth' });
          });
        }
        if (nextBtn) {
          nextBtn.addEventListener('click', () => {
            track.scrollBy({ left: scrollAmount(), behavior: 'smooth' });
          });
        }
      });
    },
  };
})(Drupal, once);
