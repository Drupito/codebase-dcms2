(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.webformSuccessModal = {

    attach: function (context, settings) {
      // Handle already-rendered inline confirmation (non-JS fallback path).
      once('webform-success-modal-confirm', '.webform-confirmation', context)
        .forEach((el) => {
          const msg = this._resolveMessage(el, settings);
          el.style.display = 'none';
          this.showModal(msg);
        });

      // Intercept every webform submit button — submit via fetch, show modal.
      once('webform-success-modal-form', 'form.webform-submission-form', context)
        .forEach((form) => {
          form.addEventListener('submit', (e) => {
            e.preventDefault();
            this._submitForm(form, settings);
          });
        });
    },

    _resolveMessage: function (confEl, settings) {
      const webformSettings = settings.webform || {};
      const webformId =
        confEl.dataset.webformId ||
        confEl.closest('[data-webform-id]')?.dataset.webformId;

      const wfEntry = webformId
        ? webformSettings[webformId]
        : Object.values(webformSettings)[0];

      if (wfEntry) {
        const s = wfEntry.settings || wfEntry;
        if (s.confirmation_title) return s.confirmation_title;
        if (s.confirmation_message) {
          const tmp = document.createElement('div');
          tmp.innerHTML = s.confirmation_message;
          const txt = tmp.textContent.trim();
          if (txt) return txt;
        }
      }

      const msgEl = confEl.querySelector('.webform-confirmation__message');
      if (msgEl && msgEl.textContent.trim()) return msgEl.textContent.trim();

      return 'Thank you! Your submission has been received.';
    },

    _resolveMessageFromForm: function (form, settings) {
      const webformSettings = settings.webform || {};
      const formId = form.dataset.webformId ||
        form.id.replace('webform-submission-', '').replace(/-add-form.*/, '').replace(/-/g, '_');

      const wfEntry = webformSettings[formId] || Object.values(webformSettings)[0];

      if (wfEntry) {
        const s = wfEntry.settings || wfEntry;
        if (s.confirmation_title) return s.confirmation_title;
        if (s.confirmation_message) {
          const tmp = document.createElement('div');
          tmp.innerHTML = s.confirmation_message;
          const txt = tmp.textContent.trim();
          if (txt) return txt;
        }
      }

      return 'Thank you! Your submission has been received.';
    },

    _submitForm: function (form, settings) {
      const submitBtn = form.querySelector('[type="submit"]');
      const originalText = submitBtn ? submitBtn.textContent : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting…';
      }

      // Clear previous inline errors.
      form.querySelectorAll('.form-item--error-message').forEach(el => el.remove());
      form.querySelectorAll('.error').forEach(el => el.classList.remove('error'));

      const data = new FormData(form);
      // Tell Drupal this is not an AJAX request so it returns a full redirect.
      data.delete('_drupal_ajax');

      fetch(form.action || window.location.href, {
        method: 'POST',
        body: data,
        headers: { 'Accept': 'text/html' },
        credentials: 'same-origin',
      })
        .then((res) => {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.text();
        })
        .then((html) => {
          const doc = new DOMParser().parseFromString(html, 'text/html');
          const hasConfirmation = doc.querySelector('.webform-confirmation');
          const hasErrors = doc.querySelector('.form-item--error-message, .messages--error');

          if (hasErrors && !hasConfirmation) {
            // Inline validation errors — show them in the form.
            this._injectErrors(form, doc);
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = originalText;
            }
            return;
          }

          // Success — show the modal.
          const msg = this._resolveMessageFromForm(form, settings);
          form.reset();
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
          }
          this.showModal(msg);
        })
        .catch(() => {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
          }
          this.showModal(this._resolveMessageFromForm(form, settings));
        });
    },

    _injectErrors: function (form, doc) {
      doc.querySelectorAll('.form-item--error-message').forEach((errEl) => {
        const fieldName = errEl.closest('.form-item')?.querySelector('[name]')?.name;
        if (fieldName) {
          const target = form.querySelector('[name="' + fieldName + '"]');
          if (target) {
            target.classList.add('error');
            const msg = document.createElement('div');
            msg.className = 'form-item--error-message';
            msg.textContent = errEl.textContent;
            target.parentNode.insertBefore(msg, target.nextSibling);
          }
        }
      });
    },

    showModal: function (message) {
      const existing = document.querySelector('.js-success-modal-overlay');
      if (existing) existing.remove();

      const wrapper = document.createElement('div');
      wrapper.innerHTML = `
        <div class="mmi-success-modal-overlay js-success-modal-overlay" role="dialog" aria-modal="true" aria-label="Success">
          <div class="mmi-success-modal">
            <button class="mmi-success-modal__close js-success-modal-close" aria-label="Close">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M15 5L5 15M5 5L15 15" stroke="black" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
            <div class="mmi-success-modal__icon">
              <img src="/themes/contrib/hourglass/assets/images/webform-submission.png" alt="" width="138" height="138">
            </div>
            <h2 class="mmi-success-modal__title">Submission Successful!</h2>
            <p class="mmi-success-modal__message">${message}</p>
          </div>
        </div>`;

      const modal = wrapper.firstElementChild;
      document.body.appendChild(modal);

      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          modal.classList.add('is-visible');
          document.body.style.overflow = 'hidden';
        });
      });

      const close = () => {
        modal.classList.remove('is-visible');
        document.body.style.overflow = '';
        setTimeout(() => modal.remove(), 300);
      };

      modal.querySelector('.js-success-modal-close').addEventListener('click', close);
      modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
      document.addEventListener('keydown', function onKey(e) {
        if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
      });
    }
  };

})(Drupal, once);
