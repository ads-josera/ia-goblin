<?php

declare(strict_types=1);

namespace Drupal\Tests\goblin\Unit;

use Drupal\goblin\Color\ColorMath;
use Drupal\goblin\Color\ColorScheme;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the color math and the derived tokens.
 */
#[CoversClass(ColorMath::class)]
#[CoversClass(ColorScheme::class)]
#[Group('goblin')]
final class ColorSchemeTest extends UnitTestCase {

  /**
   * Known WCAG ratios, as reported by browser dev tools.
   */
  public function testContrastMatchesWcag(): void {
    $this->assertEqualsWithDelta(21.0, ColorMath::contrast('#000000', '#ffffff'), 0.001);
    $this->assertEqualsWithDelta(1.0, ColorMath::contrast('#4338ca', '#4338ca'), 0.001);
    $this->assertEqualsWithDelta(4.54, ColorMath::contrast('#767676', '#ffffff'), 0.01);
  }

  /**
   * Only hex colors survive normalization; anything else is rejected.
   */
  #[DataProvider('providerNormalize')]
  public function testNormalize(mixed $input, ?string $expected): void {
    $this->assertSame($expected, ColorMath::normalize($input));
  }

  /**
   * Data provider for testNormalize().
   */
  public static function providerNormalize(): array {
    return [
      'short' => ['#ABC', '#aabbcc'],
      'no hash' => ['4338CA', '#4338ca'],
      'spaces' => ['  #4338ca ', '#4338ca'],
      'named color' => ['red', NULL],
      'bad digit' => ['#12345g', NULL],
      'css injection' => ['#fff;}body{display:none', NULL],
      'not a string' => [123456, NULL],
      'null' => [NULL, NULL],
    ];
  }

  /**
   * Stored garbage falls back per role instead of breaking the page.
   */
  public function testFromSettingsFallsBackPerRole(): void {
    $scheme = ColorScheme::fromSettings([
      'accent' => '#0F766E',
      'text' => 'javascript:alert(1)',
      'unknown' => '#000000',
    ]);
    $colors = $scheme->toArray();
    $this->assertSame('#0f766e', $colors['accent']);
    $this->assertSame(ColorScheme::DEFAULTS['text'], $colors['text']);
    $this->assertSame(array_keys(ColorScheme::DEFAULTS), array_keys($colors));
    $this->assertSame(ColorScheme::DEFAULTS, ColorScheme::fromSettings('not an array')->toArray());
  }

  /**
   * Unreadable body text is reported against each surface it fails on.
   */
  public function testTextContrastFailures(): void {
    $this->assertSame([], ColorScheme::defaults()->textContrastFailures());

    $failures = ColorScheme::fromSettings(['text' => '#bbbbbb', 'background' => '#ffffff', 'surface' => '#ffffff'])->textContrastFailures();
    $this->assertSame(['background', 'surface'], array_column($failures, 'against'));
  }

  /**
   * A light brand color keeps buttons readable and moves links to text.
   */
  public function testLightAccentFallsBack(): void {
    $scheme = ColorScheme::fromSettings(['accent' => '#facc15']);
    $tokens = $scheme->tokens();

    $this->assertFalse($scheme->linkUsesAccent());
    $this->assertSame($tokens['--goblin-color-text'], $tokens['--goblin-color-link']);
    $this->assertSame($tokens['--goblin-color-text'], $tokens['--goblin-color-focus']);
    $this->assertSame('#000000', $tokens['--goblin-color-on-accent']);
  }

  /**
   * The Goblin Creative orange keeps its white label only on large text.
   */
  public function testBrandOrangeLargeLabelIsWhite(): void {
    $tokens = ColorScheme::fromSettings(['accent' => '#e5700c'])->tokens();

    $this->assertSame('#000000', $tokens['--goblin-color-on-accent'], 'Normal-size text needs black: white is 3.16:1.');
    $this->assertSame('#ffffff', $tokens['--goblin-color-on-accent-large']);
  }

  /**
   * Every derived token meets its threshold for any legible scheme.
   */
  #[DataProvider('providerSchemes')]
  public function testDerivedTokensAreLegible(array $colors): void {
    $scheme = ColorScheme::fromSettings($colors);
    $this->assertSame([], $scheme->textContrastFailures(), 'Fixture must be a legible scheme.');
    $t = $scheme->tokens();

    foreach (['--goblin-color-background', '--goblin-color-surface'] as $background) {
      $this->assertGreaterThanOrEqual(ColorScheme::TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-text-muted'], $t[$background]), "Muted text on $background");
      $this->assertGreaterThanOrEqual(ColorScheme::TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-link'], $t[$background]), "Link on $background");
      $this->assertGreaterThanOrEqual(ColorScheme::UI_CONTRAST, ColorMath::contrast($t['--goblin-color-border-strong'], $t[$background]), "Input border on $background");
      $this->assertGreaterThanOrEqual(ColorScheme::UI_CONTRAST, ColorMath::contrast($t['--goblin-color-focus'], $t[$background]), "Focus ring on $background");
    }
    $this->assertGreaterThanOrEqual(ColorScheme::TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-on-accent'], $t['--goblin-color-accent']), 'Button label');
    $this->assertGreaterThanOrEqual(ColorScheme::TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-on-accent'], $t['--goblin-color-accent-hover']), 'Button label on hover');
    $this->assertGreaterThanOrEqual(ColorScheme::LARGE_TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-on-accent-large'], $t['--goblin-color-accent']), 'Large button label');
    $this->assertGreaterThanOrEqual(ColorScheme::LARGE_TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-on-accent-large'], $t['--goblin-color-accent-hover-large']), 'Large button label on hover');
    $this->assertGreaterThanOrEqual(ColorScheme::TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-on-header'], $t['--goblin-color-header']), 'Header text');
    $this->assertGreaterThanOrEqual(ColorScheme::TEXT_CONTRAST, ColorMath::contrast($t['--goblin-color-on-header-muted'], $t['--goblin-color-header']), 'Footer text');
  }

  /**
   * Data provider for testDerivedTokensAreLegible().
   */
  public static function providerSchemes(): array {
    return [
      'defaults' => [ColorScheme::DEFAULTS],
      'teal brand' => [[
        'accent' => '#0f766e',
        'header' => '#ffffff',
        'background' => '#ffffff',
        'surface' => '#f8fafc',
        'text' => '#0f172a',
      ],
      ],
      'yellow brand' => [[
        'accent' => '#facc15',
        'header' => '#facc15',
        'background' => '#fffbeb',
        'surface' => '#ffffff',
        'text' => '#292524',
      ],
      ],
      'mid grey accent' => [['accent' => '#808080', 'header' => '#777777']],
      'goblin creative' => [[
        'accent' => '#e5700c',
        'header' => '#ffffff',
        'background' => '#ffffff',
        'surface' => '#f7f7f8',
        'text' => '#1d1d1f',
      ],
      ],
      'dark page' => [[
        'accent' => '#a78bfa',
        'header' => '#000000',
        'background' => '#111827',
        'surface' => '#1f2937',
        'text' => '#f9fafb',
      ],
      ],
    ];
  }

  /**
   * The CSS fallback in tokens.css equals the PHP defaults.
   *
   * The PHP output is what pages use; tokens.css only covers the case where
   * it is missing. If they drift, that fallback silently shows other colors.
   */
  public function testCssFallbackMatchesDefaults(): void {
    $css = file_get_contents(dirname(__DIR__, 3) . '/css/base/tokens.css');
    preg_match('#/\* goblin-color-defaults:start \*/(.*?)/\* goblin-color-defaults:end \*/#s', $css, $block);
    $this->assertNotEmpty($block, 'Marker comments found in tokens.css.');
    preg_match_all('/(--goblin-color-[a-z-]+):\s*(#[0-9a-f]{6});/', $block[1], $pairs, PREG_SET_ORDER);
    $fallback = array_column($pairs, 2, 1);

    $this->assertSame(ColorScheme::defaults()->tokens(), $fallback);
  }

  /**
   * The rendered rule contains only hex values.
   */
  public function testToCssContainsOnlyHexValues(): void {
    $css = ColorScheme::fromSettings(['accent' => '#fff;}body{display:none'])->toCss();
    $this->assertMatchesRegularExpression('/^:root\{(--goblin-color-[a-z-]+:#[0-9a-f]{6};?)+\}$/', $css);
  }

}
