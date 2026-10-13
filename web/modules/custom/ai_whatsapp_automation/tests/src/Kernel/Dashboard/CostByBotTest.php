<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Dashboard;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the dashboard charges each reply's cost to the bot that wrote it.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class CostByBotTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $entity_type_ids = [
      'ai_whatsapp_client',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
  }

  /**
   * A conversation that moved from Support to Billing splits its cost.
   *
   * Grouping by the conversation's current bot charged Support's replies to
   * Billing, the area where the conversation ended.
   */
  public function testCostFollowsTheReplyingBot(): void {
    $etm = $this->container->get('entity_type.manager');
    $bots = $etm->getStorage('ai_whatsapp_bot');
    $support = $bots->create(['name' => 'Soporte', 'status' => 'active', 'system_prompt' => 'x']);
    $support->save();
    $billing = $bots->create(['name' => 'Facturación', 'status' => 'active', 'system_prompt' => 'x']);
    $billing->save();
    // The conversation ends in Billing.
    $conversation = $etm->getStorage('ai_whatsapp_conversation')->create([
      'phone' => 'web:1:x',
      'provider' => 'web',
      'channel' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $billing->id(),
    ]);
    $conversation->save();
    $messages = $etm->getStorage('ai_whatsapp_message');
    $reply = fn ($bot, string $cost) => $messages->create([
      'conversation' => $conversation->id(),
      'sender' => 'ai',
      'bot' => $bot->id(),
      'content' => 'respuesta',
      'tokens' => 100,
      'cost' => $cost,
    ])->save();
    $reply($support, '0.003000');
    $reply($support, '0.002000');
    $reply($billing, '0.001000');
    // Contact messages from before replies recorded their bot.
    $messages->create(['conversation' => $conversation->id(), 'sender' => 'contact', 'content' => 'hola'])->save();

    $metrics = $this->container->get('ai_whatsapp_automation.dashboard_metrics')->getMetrics(NULL, 0);

    $by_bot = array_column($metrics['cost_by_bot'], 'total_cost', 'name');
    $this->assertEqualsWithDelta(0.005, $by_bot['Soporte'] ?? 0, 0.0000001);
    $this->assertEqualsWithDelta(0.001, $by_bot['Facturación'] ?? 0, 0.0000001);
    $this->assertEqualsWithDelta(0.006, $metrics['summary']['openai_cost'], 0.0000001, 'The total is the sum');
    $by_channel = array_column($metrics['cost_by_channel'], 'total_cost', 'bot_name');
    $this->assertEqualsWithDelta(0.005, $by_channel['Soporte'] ?? 0, 0.0000001);
    // One row per channel and bot: the joined columns used to split a bot.
    $names = array_column($metrics['cost_by_channel'], 'bot_name');
    $this->assertSame(array_unique($names), $names, 'No bot repeated: ' . implode(', ', $names));
    $this->assertCount(2, $metrics['cost_by_bot']);
  }

}
