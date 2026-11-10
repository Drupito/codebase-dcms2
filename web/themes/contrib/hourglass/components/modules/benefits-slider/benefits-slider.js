/**
 * @file
 * Prev/next navigation for the Benefits Slider.
 */

(function (Drupal, once) {
  Drupal.behaviors.benefitsSlider = {
    attach(context) {
      once('benefits-slider', '.c-benefits-slider', context).forEach((section) => {
        const track = section.querySelector('.js-benefits-track');
        const prevBtn = section.querySelector('.js-benefits-prev');
        const nextBtn = section.querySelector('.js-benefits-next');
        if (!track) return;

        const scrollAmount = () => {
          const card = track.querySelector('.c-benefit-card');
          return card ? card.offsetWidth + 12 : 612;
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
