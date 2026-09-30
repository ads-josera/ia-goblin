(function (Drupal) {
  'use strict';

  /**
   * Live preview for hex color text fields ([data-aiwa-color]).
   *
   * Adds a native color picker kept in sync with the text field, and, for
   * fields that carry white text ([data-aiwa-color-white-text]), the measured
   * contrast of white on that color against the WCAG 4.5:1 minimum.
   */
  Drupal.behaviors.aiWhatsappAutomationColorPreview = {
    attach: function attach(context) {
      var inputs = context.querySelectorAll ? context.querySelectorAll('input[data-aiwa-color]') : [];
      inputs.forEach(function (input) {
        if (input.dataset.aiwaColorBound === 'true') {
          return;
        }
        input.dataset.aiwaColorBound = 'true';

        // One layout for every color field: swatch picker, then the hex
        // text, then (for white-text colors) the contrast on its own line.
        var wrapper = document.createElement('div');
        wrapper.className = 'aiwa-color-preview';
        var picker = document.createElement('input');
        picker.type = 'color';
        picker.className = 'aiwa-color-preview__picker';
        picker.setAttribute('aria-label', Drupal.t('Elegir color'));
        input.insertAdjacentElement('beforebegin', wrapper);
        wrapper.appendChild(picker);
        wrapper.appendChild(input);

        var note = null;
        if (input.hasAttribute('data-aiwa-color-white-text')) {
          note = document.createElement('div');
          note.className = 'aiwa-color-preview__note';
          note.setAttribute('aria-live', 'polite');
          wrapper.appendChild(note);
        }

        function sync() {
          var hex = normalize(input.value);
          wrapper.classList.toggle('is-invalid', hex === null);
          if (hex !== null) {
            picker.value = hex;
          }
          if (note) {
            if (hex === null) {
              note.textContent = Drupal.t('Formato: #RRGGBB');
              return;
            }
            var ratio = contrast(hex, '#ffffff');
            var ok = ratio >= 4.5;
            note.classList.toggle('is-low', !ok);
            note.textContent = Drupal.t('Texto blanco: @ratio:1 @verdict', {
              '@ratio': ratio.toFixed(2),
              '@verdict': ok ? Drupal.t('(se lee bien)') : Drupal.t('(mínimo 4.5:1: poco legible)'),
            });
          }
        }

        picker.addEventListener('input', function () {
          input.value = picker.value;
          sync();
        });
        input.addEventListener('input', sync);
        sync();
      });
    },
  };

  // #rgb or #rrggbb, with or without "#", to lowercase #rrggbb; null if invalid.
  function normalize(value) {
    var hex = String(value || '').trim().replace(/^#/, '');
    if (/^[0-9a-f]{3}$/i.test(hex)) {
      hex = hex.replace(/(.)/g, '$1$1');
    }
    return /^[0-9a-f]{6}$/i.test(hex) ? '#' + hex.toLowerCase() : null;
  }

  // WCAG 2.x contrast ratio, same formula as the Goblin theme's ColorMath.
  function contrast(a, b) {
    var la = luminance(a);
    var lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }

  function luminance(hex) {
    var channels = [1, 3, 5].map(function (i) {
      var v = parseInt(hex.substr(i, 2), 16) / 255;
      return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
  }
})(Drupal);
