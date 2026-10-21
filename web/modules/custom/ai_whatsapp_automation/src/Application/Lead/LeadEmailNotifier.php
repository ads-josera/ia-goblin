<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Lead;

use Drupal\ai_whatsapp_automation\Mail\ClientAgentMail;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the lead notice by e-mail to the people in charge of a bot.
 *
 * E-mail goes in addition to WhatsApp, never instead of it: a person in charge
 * without WhatsApp still hears about the lead, and when one channel fails the
 * other one still arrives. Web chat leads have no WhatsApp account to send
 * from, so for them e-mail can be the only channel.
 *
 * The body uses the same branded template as the account e-mails
 * (ai_whatsapp_client_agent_mail). It is HTML: the mail backend must keep it,
 * which the SMTP module does with "Allow to send e-mails formatted as HTML"
 * (smtp.settings:smtp_allowhtml). Core's php_mail turns it into plain text.
 */
final class LeadEmailNotifier {

  /**
   * Mail key handled by ai_whatsapp_automation_mail().
   */
  public const MAIL_KEY = 'lead_notification';

  /**
   * Bot field with the addresses, one per line.
   */
  public const FIELD = 'lead_notification_emails';

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a LeadEmailNotifier object.
   */
  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly RendererInterface $renderer,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ClientAgentMail $clientAgentMail,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Splits a list of addresses: one per line, commas also accepted.
   *
   * @return string[]
   *   Trimmed, non-empty addresses.
   */
  public static function parseAddresses(string $raw): array {
    $addresses = preg_split('/[\r\n,;]+/', $raw) ?: [];

    return array_values(array_unique(array_filter(array_map('trim', $addresses))));
  }

  /**
   * E-mails the lead notice to every address of the bot.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $conversation
   *   The conversation the lead comes from.
   * @param \Drupal\Core\Entity\ContentEntityInterface $lead
   *   The new or updated lead.
   * @param string $ai_response
   *   The bot's closing summary.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $bot
   *   The area bot whose people are notified.
   * @param string $event
   *   "Nuevo lead" or "Actualización del lead".
   *
   * @return array<int, array<string, string>>
   *   One result per address: channel, recipient and status (sent/failed),
   *   the same status values as the WhatsApp results.
   */
  public function notify(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, ?ContentEntityInterface $bot, string $event): array {
    if (!$bot instanceof ContentEntityInterface || !$bot->hasField(self::FIELD)) {
      return [];
    }
    $addresses = self::parseAddresses((string) $bot->get(self::FIELD)->value);
    if ($addresses === []) {
      return [];
    }

    $params = [
      'subject' => sprintf('%s %s — %s', $event, $lead->id(), $bot->label()),
      'body' => $this->render($conversation, $lead, $ai_response, $bot, $event),
    ];
    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    $results = [];
    foreach ($addresses as $address) {
      try {
        $sent = $this->mailManager->mail('ai_whatsapp_automation', self::MAIL_KEY, $address, $langcode, $params);
        $ok = !empty($sent['result']);
      }
      catch (\Throwable $e) {
        // A mail server problem must never undo the lead: it is already saved
        // and WhatsApp may still have delivered the notice.
        $this->logger->error('Lead @lead e-mail notice failed: @message', [
          '@lead' => (string) $lead->id(),
          '@message' => $e->getMessage(),
        ]);
        $ok = FALSE;
      }
      $results[] = [
        'channel' => 'email',
        'recipient' => $address,
        'status' => $ok ? 'sent' : 'failed',
      ];
    }

    $this->logger->notice('Lead @lead e-mail notice: @sent of @count sent.', [
      '@lead' => (string) $lead->id(),
      '@sent' => (string) count(array_filter($results, static fn (array $result): bool => $result['status'] === 'sent')),
      '@count' => (string) count($results),
    ]);

    return $results;
  }

  /**
   * Fills the message for hook_mail().
   *
   * @param array<string, mixed> $message
   *   The message being built.
   * @param array<string, mixed> $params
   *   Subject and rendered HTML body from ::notify().
   */
  public static function buildMessage(array &$message, array $params): void {
    $message['subject'] = (string) $params['subject'];
    $message['body'][] = Markup::create((string) $params['body']);
    // Read by the SMTP module (with HTML allowed) to send it as HTML.
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
  }

  /**
   * Renders the HTML body.
   */
  private function render(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, ContentEntityInterface $bot, string $event): string {
    $base_url = rtrim((string) ($GLOBALS['base_url'] ?? ''), '/');
    $is_update = $event !== 'Nuevo lead';
    $source = (string) ($lead->hasField('source') ? $lead->get('source')->value : '');
    $client = $bot->hasField('client') && !$bot->get('client')->isEmpty() ? (string) $bot->get('client')->entity?->label() : '';

    $build = [
      '#theme' => 'ai_whatsapp_client_agent_mail',
      '#heading' => $is_update ? $event . ' ' . $lead->id() : $event,
      '#intro' => $is_update
        ? sprintf('%s agregó datos a este caso.', $bot->label())
        : sprintf('%s registró un caso nuevo para tu área.', $bot->label()),
      '#items' => [
        'Folio: ' . $lead->id(),
        'Contacto: ' . $lead->label(),
        'Teléfono: ' . ((string) $lead->get('phone')->value ?: 'No capturado'),
        'Correo: ' . ((string) $lead->get('email')->value ?: 'No capturado'),
        'Origen: ' . ($source === 'web' ? 'Chat web' : 'WhatsApp'),
      ],
      '#summary' => $this->summary($ai_response),
      '#action' => 'Ver la conversación',
      '#url' => $base_url . '/admin/content/ai-whatsapp/conversations/' . $conversation->id(),
      '#note' => 'Para abrir la conversación inicia sesión en el panel.',
      '#client' => $client,
      '#logo_url' => $this->clientAgentMail->logoUrl(),
      '#footer' => sprintf('Recibes este aviso porque estás registrado como encargado de los leads de %s.', $bot->label()),
    ];

    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * The bot's summary without chat formatting, line breaks kept.
   */
  private function summary(string $ai_response): string {
    $summary = str_replace(['**', '__', '`'], '', trim($ai_response));

    return mb_substr($summary, 0, 1500);
  }

}
