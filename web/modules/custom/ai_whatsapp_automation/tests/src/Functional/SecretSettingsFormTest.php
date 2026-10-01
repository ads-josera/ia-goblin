<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests entering provider secrets in the settings form, as admins do.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class SecretSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_whatsapp_automation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private const PATH = 'admin/config/services/ai-whatsapp-automation';

  /**
   * The key entered in the form works, is kept and is never exported.
   */
  public function testKeyEnteredInFormSurvives(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer ai whatsapp automation']));

    $this->drupalGet(self::PATH);
    $this->submitForm(['openai[api_key]' => 'sk-from-form'], 'Save configuration');
    $this->assertSession()->statusMessageExists('status');

    $this->assertSame('sk-from-form', $this->container->get('ai_whatsapp_automation.secrets')->all()['openai.api_key'] ?? NULL);
    $this->assertSame('', $this->container->get('config.storage')->read('ai_whatsapp_automation.settings')['openai']['api_key'], 'Not in exported config');

    // The form still says it is configured, as it did before.
    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextContains('An API key is already configured.');

    // Saving again with the field empty keeps the key.
    $this->submitForm([], 'Save configuration');
    $this->assertSame('sk-from-form', $this->container->get('ai_whatsapp_automation.secrets')->all()['openai.api_key'] ?? NULL);
  }

}
