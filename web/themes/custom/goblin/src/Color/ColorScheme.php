<?php

declare(strict_types=1);

namespace Drupal\goblin\Color;

/**
 * The theme's color system: five editable roles and the tokens derived.
 *
 * Only roles are editable. Everything that must stay legible (text on
 * buttons, muted text, input borders, links, focus ring) is derived here so a
 * brand color can never produce an unreadable screen: each derived token is
 * checked against WCAG thresholds, not picked by eye.
 *
 * The CSS custom properties produced here are the single source of truth at
 * runtime. css/base/tokens.css carries the same defaults only as a fallback,
 * and ColorSchemeTest fails if the two drift apart.
 */
final class ColorScheme {

  /**
   * Minimum contrast for body text (WCAG AA, normal size).
   */
  public const TEXT_CONTRAST = 4.5;

  /**
   * Minimum contrast for UI boundaries and focus indicators (WCAG 1.4.11).
   */
  public const UI_CONTRAST = 3.0;

  /**
   * Default palette, used for any role that is missing or invalid.
   */
  public const DEFAULTS = [
    'accent' => '#4338ca',
    'header' => '#1e1b4b',
    'background' => '#f6f6fa',
    'surface' => '#ffffff',
    'text' => '#1c1b29',
  ];

  /**
   * Text colors placed on accent and header.
   *
   * Pure white and pure black on purpose: for any background, the better of
   * the two reaches at least sqrt(21) = 4.58:1, so the label on a button is
   * always legible. A softer dark such as #111111 breaks that guarantee (a
   * #777777 header gives only 4.48:1).
   */
  private const LIGHT = '#ffffff';
  private const DARK = '#000000';

  /**
   * Constructs a scheme from already-normalized role colors.
   *
   * @param array<string, string> $colors
   *   One #rrggbb value per key of self::DEFAULTS.
   */
  private function __construct(private readonly array $colors) {
  }

  /**
   * Builds the default scheme.
   */
  public static function defaults(): self {
    return new self(self::DEFAULTS);
  }

  /**
   * Builds a scheme from stored settings, falling back per role.
   *
   * Never throws: a corrupted value (for example set through drush) falls
   * back to its default instead of breaking every page of the site.
   */
  public static function fromSettings(mixed $settings): self {
    $settings = is_array($settings) ? $settings : [];
    $colors = [];
    foreach (self::DEFAULTS as $role => $default) {
      $colors[$role] = ColorMath::normalize($settings[$role] ?? NULL) ?? $default;
    }
    return new self($colors);
  }

  /**
   * Returns the editable role colors.
   *
   * @return array<string, string>
   *   Role => #rrggbb.
   */
  public function toArray(): array {
    return $this->colors;
  }

  /**
   * Returns every CSS custom property, editable and derived.
   *
   * @return array<string, string>
   *   Property name => #rrggbb.
   */
  public function tokens(): array {
    $c = $this->colors;
    $on_accent = ColorMath::mostReadable($c['accent'], self::LIGHT, self::DARK);
    $on_header = ColorMath::mostReadable($c['header'], self::LIGHT, self::DARK);

    return [
      '--goblin-color-background' => $c['background'],
      '--goblin-color-surface' => $c['surface'],
      '--goblin-color-text' => $c['text'],
      '--goblin-color-text-muted' => $this->legibleMix($c['text'], $c['background'], [$c['background'], $c['surface']], self::TEXT_CONTRAST, 0.55),
      '--goblin-color-border' => ColorMath::mix($c['text'], $c['surface'], 0.14),
      '--goblin-color-border-strong' => $this->legibleMix($c['text'], $c['surface'], [$c['background'], $c['surface']], self::UI_CONTRAST, 0.3),
      '--goblin-color-accent' => $c['accent'],
      // Moving the accent away from its text color can only raise contrast.
      '--goblin-color-accent-hover' => ColorMath::mix($c['accent'], $on_accent === self::LIGHT ? '#000000' : '#ffffff', 0.82),
      '--goblin-color-accent-soft' => ColorMath::mix($c['accent'], $c['surface'], 0.1),
      '--goblin-color-on-accent' => $on_accent,
      '--goblin-color-link' => $this->linkUsesAccent() ? $c['accent'] : $c['text'],
      '--goblin-color-focus' => $this->minContrast($c['accent'], [$c['background'], $c['surface']]) >= self::UI_CONTRAST ? $c['accent'] : $c['text'],
      '--goblin-color-header' => $c['header'],
      '--goblin-color-on-header' => $on_header,
      '--goblin-color-on-header-muted' => $this->legibleMix($on_header, $c['header'], [$c['header']], self::TEXT_CONTRAST, 0.7),
    ];
  }

  /**
   * Renders the tokens as a :root rule.
   */
  public function toCss(): string {
    $declarations = [];
    foreach ($this->tokens() as $property => $value) {
      $declarations[] = $property . ':' . $value;
    }
    return ':root{' . implode(';', $declarations) . '}';
  }

  /**
   * Lists the combinations that make body text unreadable.
   *
   * These cannot be derived away: the text color itself is the choice, so the
   * settings form refuses to save them.
   *
   * @return array<int, array{text: string, against: string, ratio: float}>
   *   One entry per failing pair; empty when the scheme is legible.
   */
  public function textContrastFailures(): array {
    $failures = [];
    foreach (['background', 'surface'] as $role) {
      $ratio = ColorMath::contrast($this->colors['text'], $this->colors[$role]);
      if ($ratio < self::TEXT_CONTRAST) {
        $failures[] = ['text' => 'text', 'against' => $role, 'ratio' => round($ratio, 2)];
      }
    }
    return $failures;
  }

  /**
   * Whether links can use the accent color and still be readable.
   *
   * When they cannot (a light brand yellow, say), links use the text color;
   * they stay identifiable because links are always underlined.
   */
  public function linkUsesAccent(): bool {
    return $this->minContrast($this->colors['accent'], [$this->colors['background'], $this->colors['surface']]) >= self::TEXT_CONTRAST;
  }

  /**
   * Lowest contrast of $color against any of $backgrounds.
   *
   * @param string $color
   *   The foreground, #rrggbb.
   * @param string[] $backgrounds
   *   Colors the foreground will be shown on.
   */
  private function minContrast(string $color, array $backgrounds): float {
    return min(array_map(static fn (string $background): float => ColorMath::contrast($color, $background), $backgrounds));
  }

  /**
   * Softest mix of $foreground into $base that still meets $threshold.
   *
   * Starts at $start (share of the foreground) and moves toward the pure
   * foreground until every background passes. Returns the foreground itself
   * when no softer mix is legible.
   *
   * @param string $foreground
   *   The full-strength color, #rrggbb.
   * @param string $base
   *   The color it is softened toward, #rrggbb.
   * @param string[] $backgrounds
   *   Colors the result must be legible on.
   * @param float $threshold
   *   Minimum contrast ratio against every background.
   * @param float $start
   *   Initial share of the foreground, from 0 to 1.
   */
  private function legibleMix(string $foreground, string $base, array $backgrounds, float $threshold, float $start): string {
    for ($weight = $start; $weight < 1.0; $weight += 0.05) {
      $candidate = ColorMath::mix($foreground, $base, $weight);
      if ($this->minContrast($candidate, $backgrounds) >= $threshold) {
        return $candidate;
      }
    }
    return $foreground;
  }

}
