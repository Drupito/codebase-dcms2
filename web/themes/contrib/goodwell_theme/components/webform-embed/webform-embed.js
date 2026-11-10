/**
 * @file webform-embed.js
 * Live-updates range slider display values.
 *
 * Webform renders each range field followed immediately by a webform_markup
 * element containing a .range-value-display div. This script watches every
 * range input and mirrors its current value into the adjacent display element.
 *
 * No changes to the Webform YAML are needed.
 */
(function () {

  function initRangeDisplays(context) {
    var ranges = (context || document).querySelectorAll(
      '.dst-webform-embed input[type="range"]'
    );

    ranges.forEach(function (input) {
      // Walk up to the closest form-item wrapper for this input.
      var formItem = input.closest('.js-form-item, .form-item');
      if (!formItem) return;

      // The display markup lives in the very next sibling form-item.
      var nextItem = formItem.nextElementSibling;
      if (!nextItem) return;

      var display = nextItem.querySelector('.range-value-display');
      if (!display) return;

      // Parse the static text once to extract prefix/suffix.
      // e.g. "25 years"  → prefix='',  suffix=' years'
      // e.g. "₹5 Crores" → prefix='₹', suffix=' Crores'
      var staticText = display.textContent.trim();
      var match = staticText.match(/^([^0-9]*)([0-9.]+)(.*)$/);
      var prefix = match ? match[1] : '';
      var suffix = match ? match[3] : '';

      function updateProgress() {
        var min = parseFloat(input.min) || 0;
        var max = parseFloat(input.max) || 100;
        var val = parseFloat(input.value) || 0;
        var pct = ((val - min) / (max - min)) * 100;
        input.style.setProperty('--range-progress', pct + '%');
      }

      function update() {
        display.textContent = prefix + input.value + suffix;
        updateProgress();
      }

      // Reflect the current slider value immediately (in case default differs).
      update();

      // Update on every slider move.
      input.addEventListener('input', update);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initRangeDisplays(document);
    });
  } else {
    initRangeDisplays(document);
  }

  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.webformRangeDisplay = {
      attach: function (context) {
        initRangeDisplays(context);
      },
    };
  }

}());
