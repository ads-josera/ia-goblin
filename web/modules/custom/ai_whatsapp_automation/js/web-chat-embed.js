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

  // 24x24 line icons, drawn for this widget. currentColor = white on the
  // bot's primary color (checked at 4.5:1 when the color is saved).
  var ICONS = {
    chat: '<path d="M12 3.5c4.7 0 8.5 3.3 8.5 7.5s-3.8 7.5-8.5 7.5c-1.2 0-2.3-.2-3.3-.6L4 19.5l1.4-3.6C4.2 14.6 3.5 12.9 3.5 11c0-4.2 3.8-7.5 8.5-7.5z"/><circle cx="8.3" cy="11" r="1.1" fill="currentColor" stroke="none"/><circle cx="12" cy="11" r="1.1" fill="currentColor" stroke="none"/><circle cx="15.7" cy="11" r="1.1" fill="currentColor" stroke="none"/>',
    sparkles: '<path d="M11 3.5l1.8 4.9 4.9 1.8-4.9 1.8L11 16.9l-1.8-4.9-4.9-1.8 4.9-1.8z"/><path d="M18.5 14.5v5M16 17h5"/><path d="M5 2.5v3M3.5 4h3"/>',
    help: '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.3c-.7.3-1.2 1-1.2 1.8v.5"/><circle cx="12" cy="16.9" r="1.1" fill="currentColor" stroke="none"/>',
    close: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>'
  };

  var STYLE = [
    ':host{all:initial}',
    '.launcher{all:unset;box-sizing:border-box;display:grid;place-items:center;width:60px;height:60px;border-radius:50%;cursor:pointer;color:#fff;',
    'background:var(--aiwa-primary);box-shadow:0 10px 28px rgba(15,23,42,.28);transition:transform .15s ease,box-shadow .15s ease}',
    '.launcher:hover{transform:scale(1.06);box-shadow:0 14px 34px rgba(15,23,42,.32)}',
    '.launcher:focus-visible{outline:3px solid var(--aiwa-primary);outline-offset:3px}',
    '.launcher svg{display:block;width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}',
    '.launcher img{display:block;width:38px;height:38px;object-fit:contain}',
    // A logo is usually drawn in the brand colors, the same as the button:
    // on a white button any logo reads. The close icon keeps the color.
    '.launcher--logo{background:#fff;color:var(--aiwa-primary)}',
    '.frame{position:fixed;bottom:92px;border:0;border-radius:16px;background:#fff;box-shadow:0 20px 55px rgba(15,23,42,.25);',
    'max-width:calc(100vw - 32px);max-height:calc(100vh - 116px)}',
    '.frame[hidden]{display:none}',
    '@media (prefers-reduced-motion:reduce){.launcher{transition:none}.launcher:hover{transform:none}}'
  ].join('');

  var SIZES = { small: ['330px', '500px'], medium: ['380px', '580px'], large: ['420px', '660px'] };

  function svg(name) {
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (ICONS[name] || ICONS.chat) + '</svg>';
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

    // The bot's own logo as the icon; built as an element, never as markup,
    // because the URL comes from the bot's settings.
    var openIcon = function () {
      if (config.icon === 'logo' && config.logoUrl) {
        var img = document.createElement('img');
        img.src = config.logoUrl;
        img.alt = '';
        return img;
      }
      var wrap = document.createElement('span');
      wrap.innerHTML = svg(config.icon);
      return wrap.firstChild;
    };

    function render(open) {
      frame.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', open ? (config.closeLabel || 'Close chat') : (config.openLabel || 'Open chat'));
      var icon = open ? null : openIcon();
      button.classList.toggle('launcher--logo', !!icon && icon.tagName === 'IMG');
      button.replaceChildren(icon || (function () {
        var wrap = document.createElement('span');
        wrap.innerHTML = svg('close');
        return wrap.firstChild;
      }()));
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
