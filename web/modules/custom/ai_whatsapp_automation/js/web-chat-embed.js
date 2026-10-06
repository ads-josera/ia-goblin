/**
 * @file
 * Floating chat button and window, embedded on any website.
 *
 * Served by WebChatController::embed() after a line that sets
 * window.AIWhatsAppAutomationWidget. It runs on the CLIENT's site, whose
 * stylesheet styles every <button> and <span> its own way: the button was
 * drawn 12.5px off centre on goblincreative.com because its theme gives all
 * buttons padding and inline-block. Everything lives in a shadow root, so no
 * rule of the host page reaches it.
 *
 * No Drupal behaviors here: the host page is not Drupal.
 */
(function () {
  'use strict';

  var STYLE = [
    ':host{all:initial}',
    '.launcher{all:unset;box-sizing:border-box;display:grid;place-items:center;width:60px;height:60px;border-radius:50%;cursor:pointer;color:#fff;',
    'background:var(--aiwa-primary);box-shadow:0 10px 28px rgba(15,23,42,.28);transition:transform .15s ease,box-shadow .15s ease}',
    '.launcher:hover{transform:scale(1.06);box-shadow:0 14px 34px rgba(15,23,42,.32)}',
    '.launcher:focus-visible{outline:3px solid var(--aiwa-primary);outline-offset:3px}',
    '.launcher svg{display:block;width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}',
    '.launcher img{display:block;width:34px;height:34px;object-fit:contain}',
    // An uploaded icon is usually drawn in the brand colors, the same as the
    // button: on a white button any icon reads. The close icon keeps the color.
    '.launcher--image{background:#fff;color:var(--aiwa-primary)}',
    '.frame{position:fixed;bottom:92px;border:0;border-radius:16px;background:#fff;box-shadow:0 20px 55px rgba(15,23,42,.25);',
    'max-width:calc(100vw - 32px);max-height:calc(100vh - 116px)}',
    '.frame[hidden]{display:none}',
    '@media (prefers-reduced-motion:reduce){.launcher{transition:none}.launcher:hover{transform:none}}'
  ].join('');

  var SIZES = { small: ['330px', '500px'], medium: ['380px', '580px'], large: ['420px', '660px'] };

  // The drawn icons come from the server (WidgetIcons, also used by the bot
  // form): trusted constants, never the bot's settings.
  function svg(markup) {
    var wrap = document.createElement('span');
    wrap.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + markup + '</svg>';
    return wrap.firstChild;
  }

  function mount() {
    var config = window.AIWhatsAppAutomationWidget || {};
    if (!config.chatUrl || document.querySelector('[data-aiwa-widget-root]')) {
      return;
    }
    var side = config.position === 'left' ? 'left' : 'right';
    var size = SIZES[config.size] || SIZES.medium;

    var host = document.createElement('div');
    host.setAttribute('data-aiwa-widget-root', 'true');
    host.style.cssText = 'position:fixed;z-index:2147483000;bottom:22px;' + side + ':22px;';
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    var style = document.createElement('style');
    style.textContent = STYLE;

    var frame = document.createElement('iframe');
    frame.className = 'frame';
    frame.title = config.name || 'Chat';
    frame.src = config.chatUrl;
    frame.hidden = true;
    frame.style[side] = '22px';
    frame.style.width = size[0];
    frame.style.height = size[1];

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'launcher';
    button.style.setProperty('--aiwa-primary', config.primaryColor || '#155EEF');
    button.setAttribute('aria-expanded', 'false');

    // The uploaded icon is built as an element, never as markup, because its
    // URL comes from the bot's settings. Without one: the drawn icon.
    var openIcon = function () {
      if (config.icon === 'custom' && config.iconUrl) {
        var img = document.createElement('img');
        img.src = config.iconUrl;
        img.alt = '';
        return img;
      }
      return svg(config.iconMarkup || '');
    };

    function render(open) {
      frame.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', open ? (config.closeLabel || 'Close chat') : (config.openLabel || 'Open chat'));
      var icon = open ? svg(config.closeMarkup || '') : openIcon();
      button.classList.toggle('launcher--image', icon.tagName === 'IMG');
      button.replaceChildren(icon);
    }

    button.addEventListener('click', function () {
      render(frame.hidden);
    });

    window.addEventListener('message', function (event) {
      if (event.source === frame.contentWindow && event.data && event.data.type === 'aiwa:minimize-chat') {
        render(false);
        button.focus();
      }
    });

    render(false);
    root.appendChild(style);
    root.appendChild(frame);
    root.appendChild(button);
    document.body.appendChild(host);
  }

  if (document.body) {
    mount();
  }
  else {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
  }
}());
