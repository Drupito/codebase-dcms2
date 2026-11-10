((Drupal) => {
  /**
   * Scale stat card text so no single word overflows the card width.
   * Runs after fonts are loaded so measurements are accurate.
   */
  function fitStatCardText(context) {
    context.querySelectorAll('.c-key-feature-slide__stat-text').forEach((el) => {
      // Reset any previous inline size so we measure from the CSS default.
      el.style.fontSize = '';
      el.style.lineHeight = '';

      const card = el.closest('.c-key-feature-slide__stat');
      if (!card) return;

      const cardStyle = getComputedStyle(card);
      const available =
        card.clientWidth -
        parseFloat(cardStyle.paddingLeft) -
        parseFloat(cardStyle.paddingRight);

      const words = el.textContent.trim().split(/\s+/);

      // Measure the widest word using a hidden off-screen span.
      const probe = document.createElement('span');
      probe.style.cssText =
        'position:absolute;visibility:hidden;white-space:nowrap;top:-9999px;left:-9999px;';
      document.body.appendChild(probe);

      let fontSize = parseFloat(getComputedStyle(el).fontSize);

      const measureMaxWord = (size) => {
        probe.style.font = `${getComputedStyle(el).fontWeight} ${size}px/${size}px ${getComputedStyle(el).fontFamily}`;
        return words.reduce((max, word) => {
          probe.textContent = word;
          return Math.max(max, probe.offsetWidth);
        }, 0);
      };

      // Binary-search down from current size until the longest word fits.
      let lo = 12;
      let hi = fontSize;
      let result = hi;

      while (lo <= hi) {
        const mid = Math.floor((lo + hi) / 2);
        if (measureMaxWord(mid) <= available) {
          result = mid;
          lo = mid + 1;
        } else {
          hi = mid - 1;
        }
      }

      document.body.removeChild(probe);

      if (result < fontSize) {
        el.style.fontSize = result + 'px';
        el.style.lineHeight = Math.round(result * 1.2) + 'px';
      }
    });
  }

  Drupal.behaviors.keyFeatureSlideTextFit = {
    attach(context) {
      // Wait for fonts before measuring so custom fonts are accounted for.
      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => fitStatCardText(context));
      } else {
        fitStatCardText(context);
      }
    },
  };
})(Drupal);
