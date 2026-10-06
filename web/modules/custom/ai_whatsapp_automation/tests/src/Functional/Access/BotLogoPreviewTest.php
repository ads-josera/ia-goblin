<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Functional\Access;

use Drupal\ai_whatsapp_automation\Access\PanelRoles;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a manager sees the widget logo they upload, before saving.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class BotLogoPreviewTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_whatsapp_automation', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Upload shows the preview; saving keeps it; the chat uses the logo.
   */
  public function testUploadedLogoIsPreviewed(): void {
    $client = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_client')->create([
      'name' => 'Goblin',
      'status' => 'active',
    ]);
    $client->save();
    $bot = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create([
      'name' => 'Goblin',
      'client' => $client->id(),
      'system_prompt' => 'Prueba.',
      'status' => 'active',
      'web_widget_enabled' => TRUE,
      'web_widget_token' => 'logo-test-token',
    ]);
    $bot->save();

    // As on the site (config/sync: authenticated users have "access
    // content"): referencing an uploaded public file needs it.
    $manager = $this->drupalCreateUser(['access content']);
    $manager->addRole(PanelRoles::MANAGER);
    $manager->save();
    $this->drupalLogin($manager);

    // A real PNG (1x1), as a manager would upload.
    $logo = $this->container->get('file_system')->getTempDirectory() . '/logo-preview-test.png';
    file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

    $this->drupalGet($bot->toUrl('edit-form'));
    $this->assertSession()->elementNotExists('css', '.aiwa-logo-preview');
    $this->submitForm(['files[web_widget_logo_file_0]' => $logo], 'Upload');
    // Before saving: the manager sees what went up, header and button.
    $this->assertSession()->elementsCount('css', '.aiwa-logo-preview img', 2);
    $this->assertSession()->elementAttributeContains('css', '.aiwa-logo-preview img', 'src', 'logo-preview-test');

    $this->submitForm(['web_widget_icon' => 'logo'], 'Save');
    $this->drupalGet($bot->toUrl('edit-form'));
    $this->assertSession()->elementsCount('css', '.aiwa-logo-preview img', 2);

    // The public chat shows it in its header.
    $this->drupalGet('ai-whatsapp-automation/chat/logo-test-token');
    $this->assertSession()->elementAttributeContains('css', 'img.aiwa-chat__logo', 'src', 'logo-preview-test');
  }

}
