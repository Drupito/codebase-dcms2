/**
 * @file premium-calculator.js
 * Live premium estimator for the Premium Calculator SDC.
 *
 * Formula (illustrative):
 *   annual = cover_amount * 0.0007 * age_multiplier * gender_multiplier
 *   monthly = annual / 12
 *
 *   age_multiplier  = 1 + (age - 18) * 0.04   (increases 4% per year)
 *   gender_multiplier = female ? 0.9 : 1.0
 */
(function () {
  "use strict";

  /* ── Calculation ───────────────────────────────── */

  function calcMonthlyPremium(age, coverLakhs, gender) {
    var coverRupees = coverLakhs * 100000;
    var ageMult = 1 + (age - 18) * 0.04;
    var genderMult = gender === "female" ? 0.9 : 1.0;
    var annual = coverRupees * 0.0007 * ageMult * genderMult;
    return Math.round(annual / 12);
  }

  /* ── Formatting ────────────────────────────────── */

  function formatPremium(amount) {
    return "$" + amount.toLocaleString("en-US") + " / month";
  }

  function formatCover(lakhs) {
    if (lakhs < 100) {
      return "$" + lakhs + "K";
    }
    var millions = lakhs / 100;
    var display = millions % 1 === 0 ? millions : millions.toFixed(1);
    return "$" + display + "M";
  }

  /* ── Slider fill ───────────────────────────────── */

  function updateSliderFill(slider) {
    var min = parseFloat(slider.min) || 0;
    var max = parseFloat(slider.max) || 100;
    var val = parseFloat(slider.value);
    var pct = ((val - min) / (max - min)) * 100;
    slider.style.setProperty("--range-progress", pct + "%");
  }

  /* ── Main init ─────────────────────────────────── */

  function initCalc(card) {
    if (card.dataset.calcInitialized === "true") return;
    card.dataset.calcInitialized = "true";

    var ageSlider    = card.querySelector("[data-calc-age]");
    var coverSlider  = card.querySelector("[data-calc-cover]");
    var genderInputs = card.querySelectorAll("[data-calc-gender]");
    var ageDisplay   = card.querySelector("[data-calc-age-display]");
    var coverDisplay = card.querySelector("[data-calc-cover-display]");
    var resultEl     = card.querySelector("[data-calc-result]");

    if (!ageSlider || !coverSlider || !resultEl) return;

    function getGender() {
      for (var i = 0; i < genderInputs.length; i++) {
        if (genderInputs[i].checked) return genderInputs[i].value;
      }
      return "male";
    }

    function update() {
      var age       = parseInt(ageSlider.value, 10);
      var cover     = parseInt(coverSlider.value, 10);
      var gender    = getGender();
      var monthly   = calcMonthlyPremium(age, cover, gender);

      if (ageDisplay)   ageDisplay.textContent   = age;
      if (coverDisplay) coverDisplay.textContent = formatCover(cover);
      resultEl.textContent = formatPremium(monthly);

      updateSliderFill(ageSlider);
      updateSliderFill(coverSlider);
    }

    ageSlider.addEventListener("input", update);
    coverSlider.addEventListener("input", update);
    genderInputs.forEach(function (input) {
      input.addEventListener("change", update);
    });

    update();
  }

  function init(context) {
    var cards = (context || document).querySelectorAll("[data-premium-calc]");
    cards.forEach(initCalc);
  }

  /* ── Bootstrap ─────────────────────────────────── */

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { init(); });
  } else {
    init();
  }

  if (typeof Drupal !== "undefined" && Drupal.behaviors) {
    Drupal.behaviors.premiumCalculator = {
      attach: function (context) {
        init(context);
      },
    };
  }
})();
