<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Webhook;

use Drupal\ai_whatsapp_automation\Controller\WebhookController;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Tests the Twilio webhook endpoint as Twilio calls it, signature included.
 *
 * Found on the first real WhatsApp test (2026-10-14): with immediate
 * processing off, replies waited for cron (up to 5 minutes), and a rejected
 * signature left no trace. OpenAI and Twilio are mocked.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class TwilioWebhookEndpointTest extends KernelTestBase {

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
    'dblog',
    'ai_whatsapp_automation',
  ];

  private const URL = 'https://ia.example.com/ai-whatsapp-automation/webhook/twilio';
  private const TOKEN = 'account-auth-token';

  /**
   * Requests captured by the mock HTTP client.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('dblog', ['watchdog']);
    $entity_type_ids = [
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_knowledge_document',
      'ai_whatsapp_knowledge_chunk',
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

    $handler = static function (RequestInterface $request) {
      return match ($request->getUri()->getHost()) {
        'api.openai.com' => Create::promiseFor(new Response(200, [], json_encode([
          'id' => 'resp_test',
          'output_text' => 'Hola, ¿en qué te ayudo?',
          'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ]))),
        'api.twilio.com' => Create::promiseFor(new Response(201, [], '{"sid":"SMreply"}')),
        default => Create::rejectionFor(new \RuntimeException('Unexpected request to ' . $request->getUri()->getHost())),
      };
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $etm = $this->container->get('entity_type.manager');
    $bot = $etm->getStorage('ai_whatsapp_bot')->create([
      'name' => 'Goblin',
      'status' => 'active',
      'system_prompt' => 'Asistente.',
    ]);
    $bot->save();
    $etm->getStorage('ai_whatsapp_account')->create([
      'name' => 'Goblin WhatsApp',
      'provider' => 'twilio',
      'phone_number' => '+5215668080368',
      'twilio_account_sid' => 'ACgoblin',
      'twilio_auth_token' => self::TOKEN,
      'status' => 'active',
      'bot' => $bot->id(),
    ])->save();
  }

  /**
   * Immediate processing on: the reply goes out during Twilio's request.
   */
  public function testImmediateProcessingRepliesRightAway(): void {
    $this->config('ai_whatsapp_automation.settings')->set('options.enable_immediate_webhook_processing', TRUE)->save();

    $response = $this->call($this->twilioRequest('SMhola', 'hola'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('immediate', json_decode((string) $response->getContent(), TRUE)['processing'] ?? NULL);
    $this->assertSame(0, $this->container->get('queue')->get('ai_whatsapp_automation_webhook')->numberOfItems(), 'Nothing left for cron');
    $this->assertContains('api.twilio.com', $this->hosts(), 'The reply was sent');
  }

  /**
   * Immediate processing off: only queued (the behaviour seen in production).
   */
  public function testQueueOnlyWaitsForCron(): void {
    $this->config('ai_whatsapp_automation.settings')->set('options.enable_immediate_webhook_processing', FALSE)->save();

    $response = $this->call($this->twilioRequest('SMhola', 'hola'));

    $this->assertSame('queued', json_decode((string) $response->getContent(), TRUE)['status'] ?? NULL);
    $this->assertSame(1, $this->container->get('queue')->get('ai_whatsapp_automation_webhook')->numberOfItems());
    $this->assertNotContains('api.twilio.com', $this->hosts());
  }

  /**
   * A wrong signature is refused and now leaves a trace, without secrets.
   */
  public function testRejectedSignatureIsLogged(): void {
    $request = $this->twilioRequest('SMbad', 'hola', 'wrong-token');

    $response = $this->call($request);

    $this->assertSame(403, $response->getStatusCode());
    $logged = (string) $this->container->get('database')
      ->query("SELECT variables FROM {watchdog} WHERE message LIKE :m", [':m' => 'Rejected %webhook: invalid signature%'])
      ->fetchField();
    $this->assertNotSame('', $logged, 'A warning was written');
    $this->assertStringContainsString('ACgoblin', $logged);
    $this->assertStringContainsString(self::URL, $logged);
    $this->assertStringNotContainsString(self::TOKEN, $logged);
    $this->assertStringNotContainsString((string) $request->headers->get('X-Twilio-Signature'), $logged);
  }

  /**
   * Builds a webhook request signed as Twilio signs it.
   */
  private function twilioRequest(string $sid, string $body, string $token = self::TOKEN): Request {
    $params = [
      'AccountSid' => 'ACgoblin',
      'MessageSid' => $sid,
      'From' => 'whatsapp:+5215512345678',
      'To' => 'whatsapp:+5215668080368',
      'Body' => $body,
      'NumMedia' => '0',
    ];
    ksort($params, SORT_STRING);
    $data = self::URL;
    foreach ($params as $key => $value) {
      $data .= $key . $value;
    }
    $request = Request::create(self::URL, 'POST', $params);
    $request->headers->set('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $data, $token, TRUE)));

    return $request;
  }

  /**
   * Calls the endpoint with the request as the current one.
   */
  private function call(Request $request): HttpResponse {
    // Drupal expects a session on every request (the kernel test one).
    $request->setSession($this->container->get('request_stack')->getCurrentRequest()->getSession());
    $this->container->get('request_stack')->push($request);
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(WebhookController::class);

    return $controller->handle($request, 'twilio');
  }

  /**
   * Hosts of the outgoing HTTP requests.
   *
   * @return string[]
   *   Hosts.
   */
  private function hosts(): array {
    return array_map(static fn (array $transaction): string => $transaction['request']->getUri()->getHost(), $this->history);
  }

}
