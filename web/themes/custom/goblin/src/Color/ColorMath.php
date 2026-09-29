<?php

declare(strict_types=1);

namespace Drupal\goblin\Color;

/**
 * Pure color arithmetic on #rrggbb strings.
 *
 * Contrast follows WCAG 2.x: relative luminance with sRGB linearization and
 * the (L1 + 0.05) / (L2 + 0.05) ratio, so results match browser dev tools.
 */
final class ColorMath {

  /**
   * Returns the color as lowercase #rrggbb, or NULL when it is not valid.
   *
   * Accepts #rgb and #rrggbb, with or without the leading hash.
   */
  public static function normalize(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $hex = ltrim(trim($value), '#');
    if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
      return NULL;
    }
    return '#' . strtolower($hex);
  }

  /**
   * Mixes two colors; $weight is the share of $a, from 0 to 1.
   */
  public static function mix(string $a, string $b, float $weight): string {
    $weight = max(0.0, min(1.0, $weight));
    $rgb_a = self::toRgb($a);
    $rgb_b = self::toRgb($b);
    $mixed = [];
    foreach ([0, 1, 2] as $channel) {
      $mixed[] = (int) round($rgb_a[$channel] * $weight + $rgb_b[$channel] * (1 - $weight));
    }
    return vsprintf('#%02x%02x%02x', $mixed);
  }

  /**
   * WCAG contrast ratio between two colors, from 1 to 21.
   */
  public static function contrast(string $a, string $b): float {
    $l1 = self::luminance($a);
    $l2 = self::luminance($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
  }

  /**
   * Returns whichever candidate has the higher contrast against $background.
   */
  public static function mostReadable(string $background, string ...$candidates): string {
    $best = $candidates[0];
    foreach ($candidates as $candidate) {
      if (self::contrast($candidate, $background) > self::contrast($best, $background)) {
        $best = $candidate;
      }
    }
    return $best;
  }

  /**
   * WCAG relative luminance.
   */
  public static function luminance(string $color): float {
    $linear = array_map(
      static function (int $channel): float {
        $value = $channel / 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
      },
      self::toRgb($color),
    );
    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
  }

  /**
   * Converts #rrggbb to [r, g, b].
   *
   * @return int[]
   *   The three channels, 0-255.
   */
  private static function toRgb(string $color): array {
    $hex = self::normalize($color) ?? throw new \InvalidArgumentException(sprintf('Invalid color "%s".', $color));
    return [
      (int) hexdec(substr($hex, 1, 2)),
      (int) hexdec(substr($hex, 3, 2)),
      (int) hexdec(substr($hex, 5, 2)),
    ];
  }

}
