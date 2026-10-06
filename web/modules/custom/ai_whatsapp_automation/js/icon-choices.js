(function (Drupal) {
  'use strict';

  /**
   * Shows a just-uploaded custom button icon on the "Custom" choice.
   *
   * The choices are drawn when the form loads; an upload only re-renders the
   * file field (AJAX), whose preview is .aiwa-logo-preview__tile--button.
   * Without this, "Custom" kept its empty "+" until the bot was saved.
   */
  Drupal.behaviors.aiWhatsappAutomationIconChoices = {
    attach: function attach() {
      var face = document.querySelector('.aiwa-icon-choices input[value="custom"] + label .aiwa-icon-choice__button');
      if (!face) {
        return;
      }
      var uploaded = document.querySelector('.aiwa-logo-preview__tile--button img');
      if (!uploaded) {
        return;
      }
      var img = face.querySelector('img');
      if (!img) {
        img = document.createElement('img');
        img.alt = '';
        face.textContent = '';
        face.classList.remove('aiwa-icon-choice__button--empty');
        face.classList.add('aiwa-icon-choice__button--image');
        face.appendChild(img);
      }
      img.src = uploaded.getAttribute('src');
    },
  };
}(Drupal));
