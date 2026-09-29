<?php

declare(strict_types=1);

namespace Drupal\Tests\goblin\Functional;

use Drupal\goblin\Color\ColorScheme;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests brand colors, logo and favicon through the real settings form.
 */
#[Group('goblin')]
#[RunTestsInSeparateProcesses]
final class ThemeSettingsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'file', 'page_cache'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'goblin';

  private const SETTINGS_PATH = 'admin/appearance/settings/goblin';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('system_branding_block', ['region' => 'header']);
    $this->drupalLogin($this->drupalCreateUser(['administer themes']));
  }

  /**
   * Colors are saved, printed in <head> and refreshed without a cache clear.
   */
  public function testColorsReachThePage(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->responseContains('--goblin-color-accent:' . ColorScheme::DEFAULTS['accent']);

    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm(['colors[accent]' => '#0f766e', 'colors[header]' => '#ffffff'], 'Save configuration');
    $this->assertSession()->statusMessageContains('The configuration options have been saved.', 'status');
    $this->assertSame('#0f766e', $this->config('goblin.settings')->get('colors.accent'));
    $this->assertArrayNotHasKey('reset', $this->config('goblin.settings')->get('colors'));

    $this->drupalGet('<front>');
    $this->assertSession()->responseContains('--goblin-color-accent:#0f766e');
    // A white header gets dark text, not white on white.
    $this->assertSession()->responseContains('--goblin-color-on-header:#000000');
    $this->assertSession()->elementAttributeContains('css', 'meta[name="theme-color"]', 'content', '#ffffff');
  }

  /**
   * Anonymous visitors see new colors despite the full-page cache.
   *
   * Only anonymous pages are cached whole, so this is the case the config
   * cache tag protects. Changing config directly (as drush or a config import
   * would) must not leave visitors on the old palette.
   */
  public function testColorChangeReachesCachedAnonymousPages(): void {
    $this->drupalLogout();
    $this->drupalGet('<front>');
    $this->drupalGet('<front>');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');

    $this->config('goblin.settings')->set('colors', ['accent' => '#0f766e'] + ColorScheme::DEFAULTS)->save();

    $this->drupalGet('<front>');
    $this->assertSession()->responseContains('--goblin-color-accent:#0f766e');
  }

  /**
   * Unreadable text is refused and nothing is saved.
   */
  public function testUnreadableTextIsRefused(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm(['colors[text]' => '#dddddd'], 'Save configuration');

    $this->assertSession()->statusMessageContains('El texto no se lee sobre', 'error');
    $this->assertNull($this->config('goblin.settings')->get('colors'));
  }

  /**
   * A light accent saves, with a warning that links use the text color.
   */
  public function testLightAccentWarns(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm(['colors[accent]' => '#facc15'], 'Save configuration');

    $this->assertSession()->statusMessageContains('los enlaces usarán el color del texto', 'warning');
    $this->drupalGet('<front>');
    $this->assertSession()->responseContains('--goblin-color-link:' . ColorScheme::DEFAULTS['text']);
  }

  /**
   * Reset restores the defaults and is not stored as a setting.
   */
  public function testReset(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm(['colors[accent]' => '#0f766e'], 'Save configuration');
    $this->submitForm(['colors[reset]' => TRUE], 'Save configuration');

    $this->assertSame(ColorScheme::DEFAULTS, $this->config('goblin.settings')->get('colors'));
  }

  /**
   * SVG, JPG and WebP logos are accepted and shown in the header.
   */
  public function testLogoFormats(): void {
    $svg = $this->writeTempFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $fixtures = $this->root . '/core/tests/fixtures/files';

    foreach (['svg' => $svg, 'jpg' => "$fixtures/image-test.jpg", 'webp' => "$fixtures/img-test.webp"] as $extension => $path) {
      $this->drupalGet(self::SETTINGS_PATH);
      $this->submitForm(['files[logo_upload]' => $path], 'Save configuration');
      $this->assertSession()->statusMessageContains('The configuration options have been saved.', 'status');

      $this->drupalGet('<front>');
      $src = $this->assertSession()->elementExists('css', '.site-logo img')->getAttribute('src');
      $this->assertStringEndsWith('.' . $extension, parse_url($src, PHP_URL_PATH), "The $extension logo is shown.");
    }
  }

  /**
   * An uploaded favicon replaces the theme's.
   */
  public function testFaviconUpload(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->elementAttributeContains('css', 'link[rel="icon"]', 'href', 'themes/custom/goblin/favicon.ico');

    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm(['files[favicon_upload]' => $this->root . '/core/tests/fixtures/files/image-test.png'], 'Save configuration');

    $this->drupalGet('<front>');
    $this->assertSession()->elementAttributeContains('css', 'link[rel="icon"]', 'href', 'image-test');
  }

  /**
   * The only action on the account screens is styled as primary.
   */
  public function testAccountFormsHavePrimaryAction(): void {
    $this->drupalLogout();
    foreach (['user/login', 'user/password'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->elementExists('css', 'form .form-actions input[type="submit"].button--primary');
    }
  }

  /**
   * Sign-in screens use the split layout with brand, slogan and artwork.
   */
  public function testAuthLayout(): void {
    $this->config('system.site')->set('slogan', 'Bot automatizado IA')->save();
    $this->drupalLogout();

    foreach (['user/login' => 'user/password', 'user/password' => 'user/login'] as $path => $secondary) {
      $this->drupalGet($path);
      $this->assertSession()->elementExists('css', '.auth .auth__panel .auth__logo');
      $this->assertSession()->elementTextEquals('css', '.auth__slogan', 'Bot automatizado IA');
      $this->assertSession()->elementAttributeContains('css', '.auth__art', 'aria-hidden', 'true');
      $this->assertSession()->elementAttributeContains('css', '.auth__secondary a', 'href', $secondary);
      $this->assertSession()->elementExists('css', 'form input[type="submit"].button--primary.button--large');
      // No site header or footer on these screens.
      $this->assertSession()->elementNotExists('css', '.site-header');
    }

    // Other pages keep the regular layout. Not <front>: the testing profile
    // uses /user/login as front page.
    $this->drupalGet('goblin-no-such-page');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->elementExists('css', '.site-header');
    $this->assertSession()->elementNotExists('css', '.auth');
  }

  /**
   * Writes a file to the test's temporary directory and returns its path.
   */
  private function writeTempFile(string $name, string $contents): string {
    $path = $this->tempFilesDirectory . '/' . $name;
    file_put_contents($path, $contents);
    return $path;
  }

}
