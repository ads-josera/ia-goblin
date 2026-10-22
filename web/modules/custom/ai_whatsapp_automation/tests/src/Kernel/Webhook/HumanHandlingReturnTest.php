<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Webhook;

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
 * Tests what a contact gets while, and after, the team has their case.
 *
 * Found in production on 2026-10-21: after a lead the conversation stayed in
 * human handling forever, so a contact writing again (the next day, or with
 * another problem) got no answer at all.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class HumanHandlingReturnTest extends KernelTestBase {

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

  private const CONTACT = '+5215512345678';

  /**
   * Requests captured by the mock HTTP client.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * The reception bot (menu).
   */
  private ContentEntityInterface $reception;

  /**
   * The support area bot.
   */
  private ContentEntityInterface $support;

  /**
   * The shared WhatsApp account (Twilio).
   */
  private ContentEntityInterface $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $entity_type_ids = [
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')
      ->set('openai.api_key', 'sk-test')
      ->set('options.enable_ai', TRUE)
      ->save();

    // OpenAI must never be called here; Twilio accepts every message.
    $handler = function (RequestInterface $request) {
      if ($request->getUri()->getHost() === 'api.twilio.com') {
        return Create::promiseFor(new Response(201, [], '{"sid":"SMtest"}'));
      }
      return Create::rejectionFor(new \RuntimeException('Unexpected outbound request to ' . $request->getUri()->getHost()));
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $bots = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot');
    $this->support = $bots->create(['name' => 'Goblin Soporte', 'status' => 'active', 'menu_label' => 'Soporte técnico']);
    $this->support->save();
    $this->reception = $bots->create(['name' => 'Goblin', 'status' => 'active', 'menu_bots' => [$this->support->id()]]);
    $this->reception->save();
    $this->account = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_account')->create([
      'name' => 'Goblin WhatsApp',
      'provider' => 'twilio',
      'phone_number' => '+5215500000000',
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $this->reception->id(),
    ]);
    $this->account->save();
  }

  /**
   * While the team has the case the contact is told so, once per burst.
   */
  public function testContactIsToldTheCaseIsWithTheTeam(): void {
    $conversation = $this->handedOverConversation();

    $first = $this->receive('SM1', '¿Ya vieron mi caso?');
    $second = $this->receive('SM2', 'Sigo sin servicio');

    $this->assertSame('saved_without_ai', $first['status']);
    $this->assertSame('sent', $first['delivery']['status']);
    $this->assertSame('skipped_rate_limited', $second['delivery']['status'], 'One reply per burst');
    $bodies = $this->twilioBodies();
    $this->assertSame(['Tu caso ya está con el equipo de Soporte técnico; te contactarán pronto. Si necesitas algo distinto, escribe «menú».'], $bodies);
    $this->assertSame('HUMAN_ASSIGNED', $this->reload($conversation)->get('status')->value, 'Still with the team');
    // The reply is in the transcript, as the system's.
    $system = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_message')->loadByProperties([
      'conversation' => $conversation->id(),
      'sender' => 'system',
    ]);
    $this->assertCount(1, $system);
  }

  /**
   * «menú» while the team has the case gives the contact the bot back.
   */
  public function testMenuGivesTheBotBack(): void {
    $conversation = $this->handedOverConversation();

    $result = $this->receive('SM1', 'Menú');

    $conversation = $this->reload($conversation);
    $this->assertSame('AI_ACTIVE', $conversation->get('status')->value);
    $this->assertSame((string) $this->reception->id(), (string) $conversation->get('bot')->target_id);
    $this->assertSame('sent', $result['delivery']['status'] ?? NULL);
    $this->assertStringContainsString('Soporte técnico', $this->twilioBodies()[0], 'The menu was sent');
    $audit = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_operator_action')->loadByProperties([
      'conversation' => $conversation->id(),
      'action' => 'AI_REACTIVATED',
    ]);
    $this->assertCount(1, $audit);
  }

  /**
   * After 24 hours without messages the bot takes the contact back.
   */
  public function testHumanHandlingEndsAfterInactivity(): void {
    $old = $this->handedOverConversation(25 * 3600);
    $recent = $this->handedOverConversation(3600, '+5215587654321');

    $closed = $this->container->get('ai_whatsapp_automation.inactive_conversation_closer')->closeInactive();

    $this->assertSame(1, $closed['HUMAN_ASSIGNED']);
    $this->assertSame('CLOSED', $this->reload($old)->get('status')->value);
    $this->assertSame((string) $this->reception->id(), (string) $this->reload($old)->get('bot')->target_id, 'Back on the reception');
    $this->assertSame('HUMAN_ASSIGNED', $this->reload($recent)->get('status')->value, 'Recent cases stay with the team');

    // The contact writes again: same conversation (history and cases kept),
    // answered with the menu, not by the area of the last case.
    $result = $this->receive('SM1', 'Hola, tengo otro problema');
    $this->assertSame((string) $old->id(), (string) $result['conversation_id']);
    $this->assertSame('AI_ACTIVE', $this->reload($old)->get('status')->value);
    $this->assertStringContainsString('Soporte técnico', $this->twilioBodies()[0]);
  }

  /**
   * With 0 hours the team keeps the conversation until someone closes it.
   */
  public function testZeroHoursKeepsHumanHandling(): void {
    $this->config('ai_whatsapp_automation.settings')->set('options.auto_release_human_hours', 0)->save();
    $old = $this->handedOverConversation(25 * 3600);

    $this->container->get('ai_whatsapp_automation.inactive_conversation_closer')->closeInactive();

    $this->assertSame('HUMAN_ASSIGNED', $this->reload($old)->get('status')->value);
  }

  /**
   * A conversation handed to Support, last touched $age seconds ago.
   */
  private function handedOverConversation(int $age = 0, string $phone = self::CONTACT): ContentEntityInterface {
    $conversation = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation')->create([
      'phone' => $phone,
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'HUMAN_ASSIGNED',
      'whatsapp_account' => $this->account->id(),
      'bot' => $this->support->id(),
      'reception_bot' => $this->reception->id(),
    ]);
    $conversation->save();
    if ($age > 0) {
      // Saving always stamps "changed": age it in the table.
      $this->container->get('database')->update('ai_whatsapp_conversation')
        ->fields(['changed' => time() - $age])
        ->condition('id', $conversation->id())
        ->execute();
    }

    return $this->reload($conversation);
  }

  /**
   * Processes an incoming Twilio message from the contact.
   *
   * @return array<string, mixed>
   *   The processor result.
   */
  private function receive(string $sid, string $body): array {
    return $this->container->get('ai_whatsapp_automation.webhook_processor')->process([
      'provider' => 'twilio',
      'message' => [
        'phone' => self::CONTACT,
        'account_phone' => '+5215500000000',
        'body' => $body,
        'provider_message_id' => $sid,
      ],
      'attempts' => 0,
      'created' => time(),
    ]);
  }

  /**
   * Bodies of the messages sent through Twilio, in order.
   *
   * @return string[]
   *   Message bodies.
   */
  private function twilioBodies(): array {
    $bodies = [];
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() === 'api.twilio.com') {
        parse_str((string) $transaction['request']->getBody(), $form);
        $bodies[] = (string) ($form['Body'] ?? '');
      }
    }

    return $bodies;
  }

  /**
   * Reloads a conversation from storage.
   */
  private function reload(ContentEntityInterface $conversation): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation');
    $storage->resetCache([$conversation->id()]);

    return $storage->load($conversation->id());
  }

}
