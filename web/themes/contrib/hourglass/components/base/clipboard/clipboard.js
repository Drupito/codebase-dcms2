/**
 * @file
 * JS for the Clipboard component.
 */

((Drupal, once) => {
  /**
   * Behavior for the Clipboard component.
   *
   * Automatically copies email addresses and phone numbers from 
   * mailto: and tel: links to the clipboard on click.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.clipboardCopy = {
    /**
     * @param {HTMLElement|Document} context
     *   The DOM element to attach the behavior to.
     */
    attach: function (context) {
      const links = once('clipboardCopy', 'a[href^="mailto:"], a[href^="tel:"]', context);
      
      links.forEach(link => {
        link.addEventListener('click', (e) => {
          e.preventDefault();
          const href = link.getAttribute('href');
          let textToCopy = '';
          
          if (href.startsWith('mailto:')) {
            textToCopy = href.replace('mailto:', '');
            textToCopy = textToCopy.split('?')[0]; 
          } else if (href.startsWith('tel:')) {
            textToCopy = href.replace('tel:', '');
          }
          
          if (textToCopy) {
            navigator.clipboard.writeText(textToCopy).then(() => {
              link.classList.add('is-copied-to-clipboard');
              setTimeout(() => {
                link.classList.remove('is-copied-to-clipboard');
              }, 2000);
            }).catch(err => {
              console.error('Failed to copy to clipboard: ', err);
            });
          }
        });
      });
    }
  };
})(Drupal, once);