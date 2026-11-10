/**
 * @file
 * Apply Section — injects the custom upload UI into the Webform managed_file
 * widget. The native file input remains functional (invisible overlay).
 */

(function (Drupal, once) {
  'use strict';

  const UPLOAD_ICON_SVG = `<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
    <path d="M6.667 13.333L10 10l3.333 3.333" stroke="#475467" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M10 10v7.5" stroke="#475467" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M16.992 15.325a4.167 4.167 0 0 0-1.992-7.833h-1.05A6.667 6.667 0 1 0 2.9 13.617" stroke="#475467" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`;

  Drupal.behaviors.applySection = {
    attach(context) {
      once('apply-upload-ui', '.c-apply-section__form-card .form-managed-file', context).forEach((wrapper) => {
        const fileInput = wrapper.querySelector('.form-file');
        if (!fileInput) return;

        // Build the visual upload area.
        const ui = document.createElement('div');
        ui.className = 'c-upload-ui';
        ui.setAttribute('aria-hidden', 'true');
        ui.innerHTML = `
          <div class="c-upload-ui__icon">${UPLOAD_ICON_SVG}</div>
          <p class="c-upload-ui__cta"><strong>Click to upload</strong> your resume</p>
          <p class="c-upload-ui__hint">pdf, doc, docx, rtf, rtfd. (max. 100mb)</p>
        `;

        // Insert visual UI before the file input.
        wrapper.insertBefore(ui, fileInput);

        // Show selected filename on the label.
        fileInput.addEventListener('change', () => {
          const file = fileInput.files && fileInput.files[0];
          const cta = wrapper.querySelector('.c-upload-ui__cta');
          if (cta) {
            cta.textContent = file ? file.name : '';
            if (!file) {
              cta.innerHTML = '<strong>Click to upload</strong> your resume';
            }
          }
        });
      });
    },
  };
})(Drupal, once);
