/**
 * AJAX pagination for the Article Listing block.
 * Intercepts pager clicks, fetches the new page, and swaps content in place,
 * then scrolls back to the top of the listing component.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.articleListingAjaxPager = {
    attach: function (context) {
      once('article-listing-ajax-pager', '.js-article-listing-view', context).forEach(function (view) {
        view.addEventListener('click', function (e) {
          var link = e.target.closest('.c-pager__item a');
          if (!link) return;

          e.preventDefault();

          var url = link.getAttribute('href');
          var contentWrapper = view.querySelector('.js-article-listing-content');
          var pagerWrapper = view.querySelector('.js-article-listing-pager');

          if (!contentWrapper || !pagerWrapper) return;

          view.classList.add('is-loading');

          fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
              if (!response.ok) throw new Error('Network response was not ok');
              return response.text();
            })
            .then(function (html) {
              var parser = new DOMParser();
              var doc = parser.parseFromString(html, 'text/html');

              var newContent = doc.querySelector('.js-article-listing-content');
              var newPager = doc.querySelector('.js-article-listing-pager');

              if (newContent) {
                contentWrapper.innerHTML = newContent.innerHTML;
              }

              if (newPager) {
                pagerWrapper.innerHTML = newPager.innerHTML;
              } else {
                pagerWrapper.innerHTML = '';
              }

              // Re-attach Drupal behaviors to newly injected markup.
              Drupal.attachBehaviors(view);

              view.classList.remove('is-loading');

              // Scroll to 200px above the top of the listing component.
              var viewTop = view.getBoundingClientRect().top + window.pageYOffset;
              window.scrollTo({ top: viewTop - 200, behavior: 'smooth' });


            })
            .catch(function () {
              view.classList.remove('is-loading');
              // Fall back to a normal page load on error.
              window.location.href = url;
            });
        });
      });
    }
  };

})(Drupal, once);
