<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the floating chat script that client websites load.
 *
 * How the button looks on a hostile page is checked in a real browser by
 * tests/visual/embed-check.mjs; this checks what the server sends.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class WebChatEmbedTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_whatsapp_automation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The chosen icon, the logo and the labels reach the script, safely.
   */
  public function testEmbedCarriesTheButtonSettings(): void {
    $bot = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create([
      'name' => 'Goblin',
      'system_prompt' => 'Prueba.',
      'status' => 'active',
      'web_widget_enabled' => TRUE,
      'web_widget_token' => 'embed-test-token',
      'web_widget_assistant_name' => 'Goblin</script><script>alert(1)</script>',
      'web_widget_icon' => 'logo',
      'web_widget_logo_url' => 'https://example.com/logo.svg',
      'web_widget_language' => 'es',
      'web_widget_primary_color' => '#065885',
    ]);
    $bot->save();

    $this->drupalGet('ai-whatsapp-automation/embed/embed-test-token');
    $this->assertSession()->statusCodeEquals(200);
    $script = $this->getSession()->getPage()->getContent();

    // The settings line, decoded as the browser would.
    $this->assertSame(1, preg_match('/^window\.AIWhatsAppAutomationWidget=(.*);$/m', $script, $matches));
    $config = json_decode($matches[1], TRUE);
    $this->assertSame('logo', $config['icon']);
    $this->assertSame('https://example.com/logo.svg', $config['logoUrl']);
    $this->assertSame('#065885', $config['primaryColor']);
    $this->assertSame('Cerrar chat', $config['closeLabel']);
    $this->assertStringStartsWith('Abrir chat con Goblin', $config['openLabel']);

    // A bot name cannot close the script tag of a page that inlines this.
    $this->assertStringNotContainsString('</script>', $matches[1]);

    // The script itself: isolated from the host page, and uses the icon.
    $this->assertStringContainsString('attachShadow', $script);
    $this->assertStringContainsString("config.icon === 'logo'", $script);
  }

}
