/**
 * @file
 * Wires the "Go Back" button to browser history navigation.
 */

(function (Drupal, once) {
  Drupal.behaviors.notFound = {
    attach(context) {
      once('not-found', '.js-not-found-back', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          window.history.back();
        });
      });
    },
  };
})(Drupal, once);
