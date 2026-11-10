/**
 * @file
 * JS for the Global Modal component.
 */

(function (Drupal, drupalSettings) {
  /**
   * Behavior for the dynamic MMI Modal.
   *
   * Displays a promotional or informational modal once per session
   * using data passed from backend settings.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.mmiModal = {
    /**
     * @param {HTMLElement|Document} context
     *   The DOM element to attach the behavior to.
     * @param {object} settings
     *   Drupal settings object; `settings.mmiModal` carries the modal data.
     */
    attach: function (context, settings) {
      if (context !== document) return;
      
      if (sessionStorage.getItem('mmi_modal_shown') === 'true') {
        return;
      }

      const modalData = settings.mmiModal && settings.mmiModal[0];
      if (!modalData) return;

      const scales = modalData.media && modalData.media.scales ? modalData.media.scales : {};
      const scaleUrls = Object.values(scales);
      if (scaleUrls.length > 0) {
        const img = new Image();
        img.src = scaleUrls[scaleUrls.length - 1];
      }

      const delay = (modalData.display_delay || 0) * 1000;

      setTimeout(() => {
        this.showModal(modalData);
      }, delay);
    },

    /**
     * Builds the modal overlay markup from backend data, appends it to the
     * body, animates it in, and wires up close interactions.
     *
     * @param {object} data - Modal configuration from `drupalSettings.mmiModal`.
     * @param {string}        data.title          - Modal heading text.
     * @param {string}        data.body           - Modal body HTML string.
     * @param {number}        [data.display_delay] - Delay in seconds before showing.
     * @param {object}        [data.media]         - Image data (urls, scales, alt text).
     * @param {Array<object>} [data.cta]           - CTA button definitions.
     */
    showModal: function (data) {
      const overlay = document.createElement('div');
      overlay.className = 'mmi-modal-overlay';
      
      const imageUrl = data.media && data.media.desktop_url ? data.media.desktop_url : '';
      const imageAlt = data.media && data.media.desktop_alt ? data.media.desktop_alt : '';
      const scales = data.media && data.media.scales ? data.media.scales : {};
      
      // Build srcset from provided scales.
      const srcset = Object.entries(scales)
        .map(([width, url]) => `${url} ${width}w`)
        .join(', ');

      const sizes = "(max-width: 900px) 100vw, 300px";

      let ctaHtml = '';
      if (data.cta && Array.isArray(data.cta)) {
        ctaHtml = data.cta.map((btn, index) => {
          const attrs = (btn.options && btn.options.attributes) ? btn.options.attributes : {};
          const variation = attrs.variation ? attrs.variation : (index === 0 ? 'primary' : 'secondary');
          const btnClass = `mmi-modal__btn--${variation}`;
          const targetAttr = attrs.target ? ` target="${attrs.target}"` : '';
          
          const isUrlEmpty = !btn.url || btn.url === '' || btn.url === 'route:<nolink>' || btn.url === '<nolink>' || btn.url === '#';
          
          if (isUrlEmpty) {
            return `
              <span class="mmi-modal__btn ${btnClass}">
                ${btn.title || ''}
              </span>
            `;
          } else {
            return `
              <a href="${btn.url}" class="mmi-modal__btn ${btnClass}"${targetAttr}>
                ${btn.title || ''}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                  <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
              </a>
            `;
          }
        }).join('');
      }

      overlay.innerHTML = `
        <button class="mmi-modal__close" aria-label="Close modal">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12.1667 0.5L0.5 12.1667M0.5 0.5L12.1667 12.1667" stroke="black" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>

        <div class="mmi-modal">
          <div class="mmi-modal__image-wrapper">
            <img 
              src="${imageUrl}" 
              srcset="${srcset}" 
              sizes="${sizes}" 
              alt="${imageAlt}" 
              class="mmi-modal__image"
            >
          </div>
          
          <div class="mmi-modal__content">
            <h2 class="mmi-modal__title">${data.title}</h2>
            <div class="mmi-modal__body">${data.body}</div>
            <div class="mmi-modal__actions">
              ${ctaHtml}
            </div>
          </div>
        </div>
      `;

      document.body.appendChild(overlay);

      requestAnimationFrame(() => {
        overlay.classList.add('is-visible');
        document.body.style.overflow = 'hidden';
      });

      const closeBtn = overlay.querySelector('.mmi-modal__close');
      const closeModal = () => {
        overlay.classList.remove('is-visible');
        document.body.style.overflow = '';
        setTimeout(() => {
          overlay.remove();
        }, 300);
      };

      closeBtn.addEventListener('click', closeModal);
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeModal();
      });

      sessionStorage.setItem('mmi_modal_shown', 'true');
    }
  };
})(Drupal, drupalSettings);
