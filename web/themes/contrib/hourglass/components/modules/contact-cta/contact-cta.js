/**
 * @file
 * GSAP ScrollTrigger animation for Contact CTA.
 * Left info card slides in from x:-42, right form panel from x:42, scrub: 1.2.
 */

(function (Drupal, once) {
  Drupal.behaviors.contactCta = {
    attach(context) {
      if (window.innerWidth < 992) return;

      once('contact-cta', '.c-contact-cta__grid', context).forEach((grid) => {
        const info = grid.querySelector('.js-contact-cta-info');
        const formPanel = grid.querySelector('.js-contact-cta-form');
        if (!info || !formPanel) return;

        gsap.registerPlugin(ScrollTrigger);

        const tl = gsap.timeline({
          scrollTrigger: {
            trigger: grid,
            start: 'top bottom',
            end: 'top 10%',
            scrub: 1.2,
          },
        });

        tl.fromTo(info, { x: -42 }, { x: 0, duration: 2, ease: 'power1.out' });
        tl.fromTo(
          formPanel,
          { x: 42 },
          { x: 0, duration: 2, ease: 'power1.out' },
          '<'
        );
      });
    },
  };
})(Drupal, once);
