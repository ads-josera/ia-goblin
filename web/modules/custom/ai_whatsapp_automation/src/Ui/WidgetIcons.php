<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Ui;

use Drupal\Core\Render\Markup;

/**
 * The floating chat button icons, defined once.
 *
 * The bot form shows them as choices and the embed script draws them on
 * client sites. Drawn twice, they would drift apart: both read them here.
 * 24x24 line icons made for this widget, drawn in currentColor.
 */
final class WidgetIcons {

  /**
   * Inner SVG markup of each drawn icon, keyed by the field value.
   */
  public const PATHS = [
    'chat' => '<path d="M12 3.5c4.7 0 8.5 3.3 8.5 7.5s-3.8 7.5-8.5 7.5c-1.2 0-2.3-.2-3.3-.6L4 19.5l1.4-3.6C4.2 14.6 3.5 12.9 3.5 11c0-4.2 3.8-7.5 8.5-7.5z"/><circle cx="8.3" cy="11" r="1.1" fill="currentColor" stroke="none"/><circle cx="12" cy="11" r="1.1" fill="currentColor" stroke="none"/><circle cx="15.7" cy="11" r="1.1" fill="currentColor" stroke="none"/>',
    'sparkles' => '<path d="M11 3.5l1.8 4.9 4.9 1.8-4.9 1.8L11 16.9l-1.8-4.9-4.9-1.8 4.9-1.8z"/><path d="M18.5 14.5v5M16 17h5"/><path d="M5 2.5v3M3.5 4h3"/>',
    'help' => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.3c-.7.3-1.2 1-1.2 1.8v.5"/><circle cx="12" cy="16.9" r="1.1" fill="currentColor" stroke="none"/>',
  ];

  /**
   * The icon shown while the chat is open.
   */
  public const CLOSE = '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>';

  /**
   * The field value that uses the uploaded button icon.
   */
  public const CUSTOM = 'custom';

  /**
   * Inner markup of an icon, the chat bubble when unknown.
   */
  public static function paths(string $icon): string {
    return self::PATHS[$icon] ?? self::PATHS['chat'];
  }

  /**
   * A complete SVG element, for the bot form.
   */
  public static function svg(string $icon): Markup {
    return Markup::create('<svg class="aiwa-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . self::paths($icon) . '</svg>');
  }

}
