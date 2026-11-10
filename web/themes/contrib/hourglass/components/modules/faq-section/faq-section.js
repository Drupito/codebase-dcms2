/**
 * @file
 * FAQ Section — accordion behaviour.
 *
 * - First item opens on init.
 * - Clicking a trigger toggles its item; closes any other open item first.
 * - Manages aria-expanded / aria-hidden for accessibility.
 */

(function (Drupal, once) {
  'use strict';

  function openItem(item) {
    const trigger = item.querySelector('[data-faq-trigger]');
    const body = item.querySelector('[data-faq-body]');
    item.classList.add('is-open');
    if (trigger) trigger.setAttribute('aria-expanded', 'true');
    if (body) body.setAttribute('aria-hidden', 'false');
  }

  function closeItem(item) {
    const trigger = item.querySelector('[data-faq-trigger]');
    const body = item.querySelector('[data-faq-body]');
    item.classList.remove('is-open');
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
    if (body) body.setAttribute('aria-hidden', 'true');
  }

  Drupal.behaviors.faqSection = {
    attach(context) {
      once('faq-section', '[data-faq-section]', context).forEach((section) => {
        const items = Array.from(section.querySelectorAll('[data-faq-item]'));

        if (!items.length) return;

        // Open the first item on init.
        openItem(items[0]);

        section.addEventListener('click', (e) => {
          const trigger = e.target.closest('[data-faq-trigger]');
          if (!trigger) return;

          const item = trigger.closest('[data-faq-item]');
          if (!item) return;

          const isOpen = item.classList.contains('is-open');

          // Close all items.
          items.forEach(closeItem);

          // Toggle: if it was closed, open it.
          if (!isOpen) openItem(item);
        });
      });
    },
  };
})(Drupal, once);
