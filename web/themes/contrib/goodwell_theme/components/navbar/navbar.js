/**
 * @file navbar.js
 * Mobile menu toggle for the Navbar SDC component.
 */
(function () {

  function bindNavbar(navbar) {
    if (navbar.dataset.navbarInitialized === 'true') return;
    navbar.dataset.navbarInitialized = 'true';

    var toggle = navbar.querySelector('[data-navbar-toggle]');
    var mobile = navbar.querySelector('[data-navbar-mobile]');

    if (!toggle || !mobile) return;

    toggle.addEventListener('click', function () {
      var isOpen = navbar.classList.contains('dst-navbar--open');

      if (isOpen) {
        close(navbar, toggle, mobile);
      } else {
        open(navbar, toggle, mobile);
      }
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
      if (!navbar.contains(e.target)) {
        close(navbar, toggle, mobile);
      }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        close(navbar, toggle, mobile);
      }
    });
  }

  function open(navbar, toggle, mobile) {
    navbar.classList.add('dst-navbar--open');
    toggle.setAttribute('aria-expanded', 'true');
    mobile.removeAttribute('hidden');
  }

  function close(navbar, toggle, mobile) {
    navbar.classList.remove('dst-navbar--open');
    toggle.setAttribute('aria-expanded', 'false');
    mobile.setAttribute('hidden', '');
  }

  function init(context) {
    var navbars = (context || document).querySelectorAll('[data-navbar]');
    navbars.forEach(bindNavbar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }

  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.navbar = {
      attach: function (context) {
        init(context);
      },
    };
  }

}());
