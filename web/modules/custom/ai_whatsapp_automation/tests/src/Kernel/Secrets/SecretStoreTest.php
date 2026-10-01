<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Secrets;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that provider secrets survive deployments and never get exported.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class SecretStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'options',
    'text',
    'views',
    'ai_whatsapp_automation',
  ];

  private const NAME = 'ai_whatsapp_automation.settings';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_whatsapp_automation']);
  }

  /**
   * A saved secret is what every service reads, and is never exported.
   */
  public function testSecretIsReadThroughConfigButNotStored(): void {
    $this->container->get('ai_whatsapp_automation.secrets')->set('openai.api_key', 'sk-test-123');
    $this->container->get('config.factory')->reset(self::NAME);

    // Services read config as before (OpenAIService, EmbeddingService…).
    $this->assertSame('sk-test-123', $this->container->get('config.factory')->get(self::NAME)->get('openai.api_key'));
    // What `drush cex` exports is the stored config: still empty.
    $stored = $this->container->get('config.storage')->read(self::NAME);
    $this->assertSame('', $stored['openai']['api_key']);
  }

  /**
   * A deployment resets the stored config; the secret is still there.
   *
   * `drush cim` writes the exported (empty) value into the stored config;
   * saving that value directly has the same effect on storage.
   */
  public function testDeploymentDoesNotWipeSecret(): void {
    $this->container->get('ai_whatsapp_automation.secrets')->set('twilio.auth_token', 'twilio-secret');
    $this->container->get('config.factory')->getEditable(self::NAME)->set('twilio.auth_token', '')->set('openai.default_model', 'gpt-5-mini')->save();
    $this->container->get('config.factory')->reset(self::NAME);

    $this->assertSame('twilio-secret', $this->container->get('config.factory')->get(self::NAME)->get('twilio.auth_token'));
  }

  /**
   * A value in settings.php / settings.local.php wins over the saved one.
   */
  public function testSettingsFileWins(): void {
    $this->container->get('ai_whatsapp_automation.secrets')->set('openai.api_key', 'sk-from-admin');
    $GLOBALS['config'][self::NAME]['openai']['api_key'] = 'sk-from-settings';
    $this->container->get('config.factory')->reset(self::NAME);

    $this->assertSame('sk-from-settings', $this->container->get('config.factory')->get(self::NAME)->get('openai.api_key'));
    unset($GLOBALS['config'][self::NAME]);
  }

  /**
   * Existing sites: secrets stored in config move out of it.
   */
  public function testUpdateMovesStoredSecrets(): void {
    $this->container->get('config.factory')->getEditable(self::NAME)
      ->set('openai.api_key', 'sk-old')
      ->set('evolution.api_key', 'evo-old')
      ->save();
    $this->container->get('module_handler')->loadInclude('ai_whatsapp_automation', 'install');

    $message = (string) ai_whatsapp_automation_update_11041();
    $this->container->get('config.factory')->reset(self::NAME);

    $stored = $this->container->get('config.storage')->read(self::NAME);
    $this->assertSame('', $stored['openai']['api_key']);
    $this->assertSame('', $stored['evolution']['api_key']);
    $this->assertSame('sk-old', $this->container->get('config.factory')->get(self::NAME)->get('openai.api_key'));
    $this->assertSame('evo-old', $this->container->get('config.factory')->get(self::NAME)->get('evolution.api_key'));
    $this->assertStringContainsString('openai.api_key', $message);
  }

  /**
   * Unknown keys are refused; an empty value removes a secret.
   */
  public function testSetRules(): void {
    $secrets = $this->container->get('ai_whatsapp_automation.secrets');
    $secrets->set('openai.api_key', 'sk-1');
    $secrets->set('openai.api_key', '');
    $this->assertSame([], $secrets->all());

    $this->expectException(\InvalidArgumentException::class);
    $secrets->set('openai.default_model', 'not-a-secret');
  }

}
