<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\AI;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_whatsapp_automation\Traits\LegacyAccountOverridesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests update_11040: account overrides move into bots, nothing changes.
 *
 * The contract is that every conversation keeps answering with the same
 * prompt, model and knowledge base after the update as before it.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class AccountOverrideMigrationTest extends KernelTestBase {

  use LegacyAccountOverridesTrait;

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

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $entity_type_ids = [
      'ai_whatsapp_client',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->container->get('module_handler')->loadInclude('ai_whatsapp_automation', 'install');
  }

  /**
   * Overriding accounts get a bot copy; everything answers as before.
   */
  public function testOverridesMoveIntoBots(): void {
    $this->installLegacyAccountOverrides();
    $client = $this->create('ai_whatsapp_client', ['name' => 'Cliente', 'status' => 'active']);
    $kb_bot = $this->knowledgeBase('Base del bot', $client);
    $kb_account = $this->knowledgeBase('Base de la cuenta', $client);
    $bot = $this->create('ai_whatsapp_bot', [
      'name' => 'Ventas',
      'client' => $client->id(),
      'system_prompt' => 'Prompt del bot.',
      'model' => 'gpt-5-mini',
      'reasoning_effort' => 'low',
      'temperature' => '0.40',
      'knowledge_base' => $kb_bot->id(),
      'status' => 'active',
      'web_widget_enabled' => TRUE,
      'web_widget_api_key' => 'secret-key',
    ]);
    $full = $this->account('Mayoristas', $client, $bot);
    $model_only = $this->account('Rápido', $client, $bot);
    $plain = $this->account('Normal', $client, $bot);
    $no_bot = $this->account('Sin bot', $client, NULL);
    $this->setLegacyAccountOverrides($full->id(), [
      'prompt_override' => 'Solo mayoristas.',
      'model_override' => 'gpt-5.1',
      'knowledge_base' => $kb_account->id(),
    ]);
    $this->setLegacyAccountOverrides($model_only->id(), ['model_override' => 'gpt-5']);
    $this->setLegacyAccountOverrides($no_bot->id(), ['prompt_override' => 'Nunca usado.']);

    $on_full = $this->conversation($full, $bot, 1754000000);
    $on_plain = $this->conversation($plain, $bot, 1754000100);
    $web = $this->create('ai_whatsapp_conversation', [
      'phone' => 'web:1',
      'channel' => 'web',
      'provider' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
      'client' => $client->id(),
    ]);

    $message = (string) ai_whatsapp_automation_update_11040();

    // The account with every override answers from a copy carrying them.
    $full_bot = $this->reload($full)->get('bot')->entity;
    $this->assertNotSame($bot->id(), $full_bot->id());
    $this->assertSame('Ventas — Mayoristas', $full_bot->label());
    $this->assertSame('Solo mayoristas.', $full_bot->get('system_prompt')->value);
    $this->assertSame('gpt-5.1', $full_bot->get('model')->value);
    $this->assertSame($kb_account->id(), $full_bot->get('knowledge_base')->target_id);
    // Everything else is inherited from the original bot.
    $this->assertSame('0.40', (string) $full_bot->get('temperature')->value);
    $this->assertSame($client->id(), $full_bot->get('client')->target_id);
    // The copy never exposes a second web chat on the original's embed.
    $this->assertFalse((bool) $full_bot->get('web_widget_enabled')->value);
    $this->assertNotSame($bot->get('web_widget_token')->value, $full_bot->get('web_widget_token')->value);
    $this->assertSame('', (string) $full_bot->get('web_widget_api_key')->value);

    // A model-only override keeps the bot's prompt and knowledge base.
    $model_bot = $this->reload($model_only)->get('bot')->entity;
    $this->assertSame('gpt-5', $model_bot->get('model')->value);
    $this->assertSame('Prompt del bot.', $model_bot->get('system_prompt')->value);
    $this->assertSame($kb_bot->id(), $model_bot->get('knowledge_base')->target_id);

    // Ongoing conversations move with their account: they keep their bot, so
    // leaving them on the original would silently change how they answer.
    $this->assertSame($full_bot->id(), $this->reload($on_full)->get('bot')->target_id);
    $this->assertSame('1754000000', (string) $this->reload($on_full)->get('changed')->value, 'Activity date untouched');

    // Accounts and conversations without overrides are untouched.
    $this->assertSame($bot->id(), $this->reload($plain)->get('bot')->target_id);
    $this->assertSame($bot->id(), $this->reload($on_plain)->get('bot')->target_id);
    $this->assertSame('1754000100', (string) $this->reload($on_plain)->get('changed')->value);
    $this->assertSame($bot->id(), $this->reload($web)->get('bot')->target_id);
    $original = $this->reload($bot);
    $this->assertSame('Prompt del bot.', $original->get('system_prompt')->value);
    $this->assertTrue((bool) $original->get('web_widget_enabled')->value);
    $this->assertSame('secret-key', $original->get('web_widget_api_key')->value);

    // An account with no bot never used its overrides: reported, no bot made.
    $this->assertNull($this->reload($no_bot)->get('bot')->target_id);
    $this->assertStringContainsString('Sin bot', $message);
    $this->assertCount(3, $this->storage('ai_whatsapp_bot')->loadMultiple());

    // The columns and their definitions are gone; nothing is left pending.
    $schema = $this->container->get('database')->schema();
    $manager = $this->container->get('entity.definition_update_manager');
    foreach (['prompt_override', 'model_override', 'knowledge_base'] as $field_name) {
      $this->assertFalse($schema->fieldExists('ai_whatsapp_account', $field_name), "$field_name column dropped");
      $this->assertNull($manager->getFieldStorageDefinition($field_name, 'ai_whatsapp_account'));
    }
    // Only the account matters here: this test installs a subset of the
    // module's entity types, so needsUpdates() would report the others.
    $this->assertArrayNotHasKey('ai_whatsapp_account', $manager->getChangeList());

    // Running it again changes nothing.
    ai_whatsapp_automation_update_11040();
    $this->assertCount(3, $this->storage('ai_whatsapp_bot')->loadMultiple());
  }

  /**
   * On a site that never had the columns the update is a no-op.
   */
  public function testFreshSiteIsUntouched(): void {
    $message = (string) ai_whatsapp_automation_update_11040();

    $this->assertStringContainsString('no account was using them', $message);
    $this->assertArrayNotHasKey('ai_whatsapp_account', $this->container->get('entity.definition_update_manager')->getChangeList());
  }

  /**
   * Creates an active knowledge base of a client.
   */
  private function knowledgeBase(string $name, ContentEntityInterface $client): ContentEntityInterface {
    return $this->create('ai_whatsapp_knowledge_base', [
      'name' => $name,
      'embedding_model' => 'x',
      'status' => 'active',
      'client' => $client->id(),
    ]);
  }

  /**
   * Creates an active WhatsApp account of a client, with or without a bot.
   */
  private function account(string $name, ContentEntityInterface $client, ?ContentEntityInterface $bot): ContentEntityInterface {
    return $this->create('ai_whatsapp_account', [
      'name' => $name,
      'provider' => 'evolution',
      'phone_number' => $name,
      'status' => 'active',
      'bot' => $bot?->id(),
      'client' => $client->id(),
    ]);
  }

  /**
   * Creates a WhatsApp conversation on an account with a given date.
   */
  private function conversation(ContentEntityInterface $account, ContentEntityInterface $bot, int $changed): ContentEntityInterface {
    $conversation = $this->create('ai_whatsapp_conversation', [
      'phone' => '+52' . $account->id(),
      'channel' => 'whatsapp',
      'provider' => 'evolution',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
      'whatsapp_account' => $account->id(),
      'client' => $account->get('client')->target_id,
    ]);
    $this->container->get('database')->update('ai_whatsapp_conversation')->fields(['changed' => $changed])->condition('id', $conversation->id())->execute();
    return $conversation;
  }

  /**
   * Creates and saves an entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param array<string, mixed> $values
   *   Field values.
   */
  private function create(string $entity_type_id, array $values): ContentEntityInterface {
    $entity = $this->storage($entity_type_id)->create($values);
    $entity->save();
    return $entity;
  }

  /**
   * Reloads an entity from storage, bypassing the static cache.
   */
  private function reload(ContentEntityInterface $entity): ContentEntityInterface {
    $storage = $this->storage($entity->getEntityTypeId());
    $storage->resetCache([$entity->id()]);
    return $storage->load($entity->id());
  }

  /**
   * Returns an entity storage.
   */
  private function storage(string $entity_type_id): mixed {
    return $this->container->get('entity_type.manager')->getStorage($entity_type_id);
  }

}
