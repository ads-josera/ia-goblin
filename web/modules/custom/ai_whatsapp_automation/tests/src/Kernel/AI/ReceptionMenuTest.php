<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\AI;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * Tests one WhatsApp number with one bot per area behind a reception menu.
 *
 * OpenAI and Twilio are replaced by a mock HTTP handler: nothing leaves the
 * test process.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ReceptionMenuTest extends KernelTestBase {

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

  private const CUSTOMER = '+5215512345678';
  private const SHARED_NUMBER = '+5213342701566';
  private const BILLING_PERSON = '+5215511111111';
  private const SUPPORT_PERSON = '+5215522222222';

  /**
   * Requests captured by the mock HTTP client.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * Replies the mocked assistant returns, in order.
   *
   * @var string[]
   */
  private array $assistantReplies = [];

  /**
   * The bots of the test: reception, billing, support.
   *
   * @var array<string, \Drupal\Core\Entity\ContentEntityInterface>
   */
  private array $bots = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    foreach ([
      'ai_whatsapp_client',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_knowledge_document',
      'ai_whatsapp_knowledge_chunk',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')
      ->set('openai.api_key', 'sk-test')
      ->set('options.enable_ai', TRUE)
      ->set('options.enable_lead_notifications', TRUE)
      ->save();

    $handler = function (RequestInterface $request) {
      $host = $request->getUri()->getHost();
      if ($host === 'api.openai.com') {
        return Create::promiseFor(new Response(200, [], json_encode([
          'id' => 'resp_test',
          'output_text' => array_shift($this->assistantReplies) ?? 'OK',
          'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
        ])));
      }
      if ($host === 'api.twilio.com') {
        return Create::promiseFor(new Response(201, [], '{"sid":"SMtest"}'));
      }
      return Create::rejectionFor(new \RuntimeException('Unexpected outbound request to ' . $host));
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot');
    $lead_rules = [
      'handoff_enabled' => TRUE,
      'handoff_minimum_fields' => 2,
      'handoff_required_fields' => "nombre\ncelular",
      'handoff_trigger_phrases' => 'Datos capturados',
    ];
    $this->bots['billing'] = $storage->create([
      'name' => 'Goblin Facturación',
      'status' => 'active',
      'system_prompt' => 'PROMPT-FACTURACION',
      'menu_label' => 'Facturación y pagos',
      'menu_keywords' => 'factura, facturacion, pago',
      'lead_notification_numbers' => self::BILLING_PERSON,
    ] + $lead_rules);
    $this->bots['support'] = $storage->create([
      'name' => 'Goblin Soporte',
      'status' => 'active',
      'system_prompt' => 'PROMPT-SOPORTE',
      'menu_label' => 'Soporte técnico',
      'menu_keywords' => 'soporte, correo, hosting',
      'lead_notification_numbers' => self::SUPPORT_PERSON,
    ] + $lead_rules);
    $this->bots['billing']->save();
    $this->bots['support']->save();
    $this->bots['reception'] = $storage->create([
      'name' => 'Goblin',
      'status' => 'active',
      'system_prompt' => 'PROMPT-RECEPCION',
      'menu_message' => '👋 Bienvenido a Goblin Creative.',
      'menu_bots' => [$this->bots['billing']->id(), $this->bots['support']->id()],
    ]);
    $this->bots['reception']->save();

    $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_account')->create([
      'name' => 'Goblin WhatsApp',
      'provider' => 'twilio',
      'phone_number' => self::SHARED_NUMBER,
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $this->bots['reception']->id(),
    ])->save();
  }

  /**
   * Menu, area, lead to that area's person, and back to the menu.
   */
  public function testMenuHandsTheConversationToTheChosenArea(): void {
    // A greeting gets the menu, without the model.
    $result = $this->send('SM1', 'Hola');
    $this->assertSame(0, $this->openAiCalls(), 'The menu never calls the model');
    $menu = (string) $result['response_text'];
    $this->assertStringContainsString('👋 Bienvenido a Goblin Creative.', $menu);
    $this->assertStringContainsString("1\u{FE0F}\u{20E3} Facturación y pagos", $menu);
    $this->assertStringContainsString("2\u{FE0F}\u{20E3} Soporte técnico", $menu);
    $this->assertSame('sent', $result['delivery']['status'] ?? NULL, 'The menu reaches WhatsApp');
    $this->assertSame('reception', $this->currentBot());

    // Choosing 2: support answers that same message with its own prompt.
    $this->assistantReplies = ['Estás en Soporte técnico. ¿Qué problema tienes?'];
    $this->send('SM2', '2️⃣');
    $this->assertSame('support', $this->currentBot());
    $this->assertSame(1, $this->openAiCalls());
    $this->assertStringContainsString('PROMPT-SOPORTE', $this->lastOpenAiBody());
    $this->assertStringNotContainsString('PROMPT-FACTURACION', $this->lastOpenAiBody());

    // From now on support answers; its lead notifies support's person only.
    $this->assistantReplies = ["Datos capturados:\nNombre: Ana Ruiz\nCelular: 5512345678"];
    $result = $this->send('SM3', 'Mi nombre es Ana Ruiz, celular 5512345678, quiero cancelar mi hosting');
    $leads = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_lead')->loadMultiple();
    $this->assertCount(1, $leads);
    $this->assertSame((string) $this->bots['support']->id(), (string) reset($leads)->get('bot')->target_id);
    $recipients = $this->twilioRecipients();
    $this->assertContains('whatsapp:' . self::SUPPORT_PERSON, $recipients);
    $this->assertNotContains('whatsapp:' . self::BILLING_PERSON, $recipients);
  }

  /**
   * Writing "menú" goes back to the reception; a keyword goes to an area.
   */
  public function testMenuWordAndKeywords(): void {
    // A keyword skips the menu.
    $this->assistantReplies = ['Claro, te ayudo con tu factura.'];
    $this->send('SM1', 'Necesito mi FACTURA de septiembre');
    $this->assertSame('billing', $this->currentBot());
    $this->assertStringContainsString('PROMPT-FACTURACION', $this->lastOpenAiBody());

    // "Menú", with any accent or capitals, returns to the menu without AI.
    $calls = $this->openAiCalls();
    $result = $this->send('SM2', 'MENÚ');
    $this->assertSame($calls, $this->openAiCalls());
    $this->assertSame('reception', $this->currentBot());
    $this->assertStringContainsString('Soporte técnico', (string) $result['response_text']);

    // Two areas match: the menu asks instead of guessing.
    $this->send('SM3', 'la factura del hosting');
    $this->assertSame('reception', $this->currentBot());
    $this->assertSame($calls, $this->openAiCalls());

    // A number that is not an option: the menu again.
    $this->send('SM4', '7');
    $this->assertSame('reception', $this->currentBot());

    // "Menú" is only a command on its own: inside a sentence it is text.
    $this->assistantReplies = ['Soporte aquí.', 'Te explico el menú de cPanel.'];
    $this->send('SM5', '2');
    $this->send('SM6', 'no encuentro el menú de cPanel');
    $this->assertSame('support', $this->currentBot());
  }

  /**
   * The person in charge of one area is never answered as a customer.
   */
  public function testAreaPersonIsNotTreatedAsCustomer(): void {
    $result = $this->send('SM1', 'Hola, soy de facturación', self::BILLING_PERSON);
    $this->assertSame('blocked_notification_recipient', $result['status']);
    $this->assertSame(0, $this->openAiCalls());
  }

  /**
   * Web chat: same menu, and area conversations count for the reception.
   */
  public function testWebChatUsesTheMenuAndKeepsItsLimits(): void {
    $this->bots['reception']->set('web_widget_enabled', TRUE)
      ->set('web_widget_daily_conversation_limit', 1)
      ->save();
    $web_chat = $this->container->get('ai_whatsapp_automation.web_chat');

    $first = $web_chat->processMessage($this->bots['reception'], 'session-a', 'hola');
    $this->assertStringContainsString('Soporte técnico', $first['message']);
    $this->assistantReplies = ['Facturación aquí.'];
    $web_chat->processMessage($this->bots['reception'], 'session-a', '1');
    $conversation = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation')->load($first['conversation_id']);
    $this->assertSame((string) $this->bots['billing']->id(), (string) $conversation->get('bot')->target_id);

    // That conversation now belongs to billing but started on the reception:
    // it still uses the reception's daily conversation limit.
    $this->expectException('Drupal\ai_whatsapp_automation\Exception\WebChatLimitException');
    $web_chat->processMessage($this->bots['reception'], 'session-b', 'hola');
  }

  /**
   * A reception cannot offer another client's bot.
   */
  public function testMenuBotsMustBelongToTheSameClient(): void {
    $clients = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_client');
    $own = $clients->create(['name' => 'Goblin']);
    $other = $clients->create(['name' => 'Otro cliente']);
    $own->save();
    $other->save();
    $this->bots['reception']->set('client', $own->id());
    $this->bots['billing']->set('client', $own->id())->save();
    $this->bots['support']->set('client', $other->id())->save();

    $conflicts = $this->container->get('ai_whatsapp_automation.client_consistency')->conflicts($this->bots['reception']);
    $this->assertArrayHasKey('menu_bots', $conflicts);
    $this->assertStringContainsString('Goblin Soporte', (string) $conflicts['menu_bots']);
  }

  /**
   * Sends a WhatsApp message to the shared number.
   *
   * @return array<string, mixed>
   *   Processing result.
   */
  private function send(string $sid, string $body, string $from = self::CUSTOMER): array {
    return $this->container->get('ai_whatsapp_automation.webhook_processor')->process([
      'provider' => 'twilio',
      'message' => [
        'phone' => $from,
        'account_phone' => self::SHARED_NUMBER,
        'body' => $body,
        'provider_message_id' => $sid,
      ],
      'attempts' => 0,
      'created' => time(),
    ]);
  }

  /**
   * Returns which test bot the customer's conversation is on.
   */
  private function currentBot(): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('phone', self::CUSTOMER)->execute();
    $conversation = $storage->load(reset($ids));
    $this->assertInstanceOf(ContentEntityInterface::class, $conversation);
    foreach ($this->bots as $key => $bot) {
      if ((string) $bot->id() === (string) $conversation->get('bot')->target_id) {
        return $key;
      }
    }

    return 'none';
  }

  /**
   * Counts the requests sent to OpenAI.
   */
  private function openAiCalls(): int {
    return count(array_filter($this->history, static fn (array $transaction): bool => $transaction['request']->getUri()->getHost() === 'api.openai.com'));
  }

  /**
   * Returns the body of the last request sent to OpenAI.
   */
  private function lastOpenAiBody(): string {
    $body = '';
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() === 'api.openai.com') {
        $body = (string) $transaction['request']->getBody();
      }
    }

    return $body;
  }

  /**
   * Returns the "To" of every message sent through Twilio.
   *
   * @return string[]
   *   Recipients.
   */
  private function twilioRecipients(): array {
    $recipients = [];
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() === 'api.twilio.com') {
        parse_str((string) $transaction['request']->getBody(), $form);
        $recipients[] = (string) ($form['To'] ?? '');
      }
    }

    return $recipients;
  }

}
