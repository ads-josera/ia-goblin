<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Lead;

use Drupal\ai_whatsapp_automation\Application\Webhook\ProviderMessageSenderService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects qualified WhatsApp leads and notifies administrators.
 */
final class LeadHandoffService {

  /**
   * The assistant's closing that opens a new case (see the handoff rules).
   */
  private const NEW_CASE_CLOSING = '/datos capturados\s*:/iu';

  /**
   * The assistant's closing that adds data to the area's latest case.
   */
  private const UPDATE_CLOSING = '/datos adicionales\s*:/iu';

  /**
   * Stored when the chat did not provide a usable contact name.
   */
  public const UNKNOWN_CONTACT_NAME = 'Nombre no capturado';

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a LeadHandoffService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProviderMessageSenderService $messageSender,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly LeadContactExtractor $contactExtractor,
    private readonly LeadEmailNotifier $emailNotifier,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Handles a possible handoff after an AI response.
   *
   * @return array<string, mixed>
   *   Handoff status.
   */
  public function handle(ContentEntityInterface $conversation, string $ai_response): array {
    $config = $this->configFactory->get('ai_whatsapp_automation.settings');
    if (!$config->get('options.enable_lead_notifications')) {
      return ['status' => 'disabled'];
    }

    $bot = $this->getBotForConversation($conversation);
    if ($bot instanceof ContentEntityInterface && $bot->hasField('handoff_enabled') && !(bool) $bot->get('handoff_enabled')->value) {
      return ['status' => 'disabled_for_bot'];
    }

    // More data for a case already registered in this area: it goes to that
    // lead, so "lo agrego a tu caso" is true. Web chat only, in practice: on
    // WhatsApp the AI stops once a lead exists and a person takes over.
    if (preg_match(self::UPDATE_CLOSING, $ai_response)) {
      $lead = $this->latestLead($conversation, $bot);
      if ($lead instanceof ContentEntityInterface) {
        return $this->updateLead($conversation, $lead, $ai_response);
      }
    }

    if (!$this->isLeadReady($conversation, $ai_response, $bot)) {
      return ['status' => 'not_ready'];
    }

    // One conversation can hold several cases (a support problem, then an
    // invoice question in Billing): an explicit new "Datos capturados:"
    // closing opens another lead. Any other closing-like reply after the
    // first lead does not, so a repeated summary cannot duplicate it.
    if ($this->hasHandoffAction($conversation) && !preg_match(self::NEW_CASE_CLOSING, $ai_response)) {
      return ['status' => 'already_notified'];
    }

    $lead = $this->createLead($conversation, $ai_response, $bot);
    $conversation->set('status', 'HUMAN_ASSIGNED');
    $conversation->save();
    $this->auditHandoff($conversation, $lead);

    $notifications = $this->notifyAdministrators($conversation, $lead, $ai_response, $bot);
    if ($notifications === []) {
      $this->logger->warning('Lead handoff created for conversation @conversation, but no notification recipients (WhatsApp numbers or e-mails) were configured.', [
        '@conversation' => (string) $conversation->id(),
      ]);

      return [
        'status' => 'created_without_recipients',
        'lead_id' => $lead->id(),
        'notifications' => [],
      ];
    }

    $successful_notifications = array_filter($notifications, static fn (array $notification): bool => ($notification['status'] ?? '') === 'sent');
    if ($successful_notifications === []) {
      $this->logger->error('Lead handoff created for conversation @conversation, but every administrator notification failed.', [
        '@conversation' => (string) $conversation->id(),
      ]);

      return [
        'status' => 'created_notification_failed',
        'lead_id' => $lead->id(),
        'notifications' => $notifications,
      ];
    }

    return [
      'status' => count($successful_notifications) === count($notifications) ? 'notified' : 'partially_notified',
      'lead_id' => $lead->id(),
      'notifications' => $notifications,
    ];
  }

  /**
   * Checks whether the recent conversation looks ready for human follow-up.
   */
  private function isLeadReady(ContentEntityInterface $conversation, string $ai_response, ?ContentEntityInterface $bot): bool {
    $contact_text = mb_strtolower($this->recentContactText($conversation));
    $text = mb_strtolower($this->recentConversationText($conversation) . "\n" . $ai_response);
    $conversation_phone = $conversation->hasField('phone') ? trim((string) $conversation->get('phone')->value) : '';
    $is_web_conversation = $conversation->hasField('provider') && $conversation->get('provider')->value === 'web';

    $has_contact = (!$is_web_conversation && $conversation_phone !== '')
      || str_contains($contact_text, '@')
      || preg_match('/\b(?:tel[eé]fono|celular|whatsapp|contacto|correo|email)\b/u', $contact_text)
      || preg_match('/(?:\+?52\s?1?\s?)?\d{10,}/', $contact_text);
    $has_handoff_signal = $this->containsAnySignal(mb_strtolower($ai_response), $this->handoffTriggerPhrases($bot));
    // These are natural closing phrases used by the assistant once it has
    // captured the service data. They are intentionally independent from a
    // bot's optional custom trigger list.
    $has_summary_signal = preg_match('/\b(?:datos recibidos|confirmo los datos|datos capturados|resumo|resumen|gracias por la informaci[oó]n|informaci[oó]n inicial|pr[oó]ximo paso|solicitud lista|preparar[aá] una propuesta)\b/u', mb_strtolower($ai_response));
    // Before the assistant confirms a summary, count only information the
    // contact supplied. This prevents a bot's intake checklist from creating
    // a lead merely because it listed the fields it still needs.
    $signal_text = $has_summary_signal ? $text : $contact_text;
    $signal_count = $this->countSignalGroups($signal_text, $this->handoffRequiredSignals($bot));
    $minimum_signals = $this->minimumSignals($bot);

    return (bool) ($has_contact && $signal_count >= $minimum_signals && ($has_handoff_signal || $has_summary_signal));
  }

  /**
   * Counts configured signal groups detected in conversation text.
   */
  private function countSignalGroups(string $text, array $signal_groups): int {
    $count = 0;
    foreach ($signal_groups as $signals) {
      if ($this->containsAnySignal($text, $signals)) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Checks whether text contains any of the provided literal signals.
   *
   * @param string[] $signals
   *   Literal signals to match.
   */
  private function containsAnySignal(string $text, array $signals): bool {
    foreach ($signals as $signal) {
      $signal = trim(mb_strtolower($signal));
      if ($signal === '') {
        continue;
      }
      if (str_contains($text, $signal)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns the required handoff signal groups for a bot.
   *
   * @return array<int, string[]>
   *   Signal groups.
   */
  private function handoffRequiredSignals(?ContentEntityInterface $bot): array {
    $configured = $bot instanceof ContentEntityInterface ? $this->getFieldValue($bot, 'handoff_required_fields') : '';
    if ($configured !== '') {
      return $this->parseSignalGroups($configured);
    }

    return [
      ['empresa', 'negocio', 'cliente', 'nombre'],
      ['mercancía', 'mercancia', 'producto', 'servicio', 'interés', 'interes', 'necesidad'],
      ['origen', 'ubicación', 'ubicacion', 'ciudad'],
      ['destino', 'entrega', 'cobertura'],
      ['medio', 'transporte', 'modalidad', 'multimodal', 'terrestre', 'marítimo', 'maritimo', 'aéreo', 'aereo'],
      ['valor', 'monto', 'presupuesto', 'usd', 'mxn', '$'],
      ['frecuencia', 'cantidad', 'volumen', 'embarque', 'embarques', 'mensual', 'semanal', 'ocasional'],
      ['contacto', 'teléfono', 'telefono', 'whatsapp', 'correo', 'email', '@'],
    ];
  }

  /**
   * Returns handoff trigger phrases for a bot.
   *
   * @return string[]
   *   Trigger phrases.
   */
  private function handoffTriggerPhrases(?ContentEntityInterface $bot): array {
    $configured = $bot instanceof ContentEntityInterface ? $this->getFieldValue($bot, 'handoff_trigger_phrases') : '';
    $default_phrases = [
      'asesor',
      'especialista',
      'ejecutivo',
      'representante',
      'humano',
      'propuesta personalizada',
      'se pondrá en contacto',
      'se pondra en contacto',
      'elaborará una propuesta',
      'elaborara una propuesta',
      'seguimiento',
      'contactar',
      'cotización',
      'cotizacion',
      'solicitud',
      'agendar',
      'reservar',
      'contratar',
      'próximo paso',
      'proximo paso',
      'confirmo los datos',
      'datos capturados',
      'solicitud lista',
      'revisará la operación',
      'revisara la operacion',
      'preparará una propuesta',
      'preparara una propuesta',
    ];

    // Custom phrases expand the reliable defaults instead of replacing them.
    // This prevents a bot-specific setup from accidentally disabling normal
    // lead handoff wording such as "Próximo paso" or "asesor".
    return array_values(array_unique(array_merge(
      $default_phrases,
      $configured === '' ? [] : $this->parseSignalList($configured),
    )));
  }

  /**
   * Returns the minimum configured signal count.
   */
  private function minimumSignals(?ContentEntityInterface $bot): int {
    if (!$bot instanceof ContentEntityInterface || !$bot->hasField('handoff_minimum_fields') || $bot->get('handoff_minimum_fields')->isEmpty()) {
      return 5;
    }

    return max(1, (int) $bot->get('handoff_minimum_fields')->value);
  }

  /**
   * Parses one signal group per line with comma alternatives.
   *
   * @return array<int, string[]>
   *   Signal groups.
   */
  private function parseSignalGroups(string $value): array {
    $groups = [];
    foreach (preg_split('/\R+/', $value) ?: [] as $line) {
      $signals = $this->parseSignalList($line);
      if ($signals !== []) {
        $groups[] = $signals;
      }
    }

    return $groups;
  }

  /**
   * Parses signals separated by commas, pipes, or new lines.
   *
   * @return string[]
   *   Signals.
   */
  private function parseSignalList(string $value): array {
    $signals = preg_split('/[\r\n,|]+/', $value) ?: [];

    return array_values(array_filter(array_map('trim', $signals), static fn (string $signal): bool => $signal !== ''));
  }

  /**
   * Loads a compact transcript from recent messages.
   */
  private function recentConversationText(ContentEntityInterface $conversation): string {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->sort('id', 'DESC')
      ->range(0, 12)
      ->execute();

    $messages = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->loadMultiple($ids);

    $lines = [];
    foreach (array_reverse($messages) as $message) {
      if (!$message instanceof ContentEntityInterface) {
        continue;
      }
      $lines[] = (string) $message->get('sender')->value . ': ' . (string) $message->get('content')->value;
    }

    return implode("\n", $lines);
  }

  /**
   * Loads only recent messages supplied by the contact.
   */
  private function recentContactText(ContentEntityInterface $conversation): string {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->condition('sender', 'contact')
      ->sort('id', 'DESC')
      ->range(0, 12)
      ->execute();

    $messages = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->loadMultiple($ids);
    $lines = [];
    foreach (array_reverse($messages) as $message) {
      if ($message instanceof ContentEntityInterface) {
        $lines[] = (string) $message->get('content')->value;
      }
    }

    return implode("\n", $lines);
  }

  /**
   * Returns the latest lead of this conversation in the bot's area, if any.
   */
  private function latestLead(ContentEntityInterface $conversation, ?ContentEntityInterface $bot): ?ContentEntityInterface {
    $query = $this->entityTypeManager
      ->getStorage('ai_whatsapp_lead')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->sort('id', 'DESC')
      ->range(0, 1);
    if ($bot instanceof ContentEntityInterface) {
      $query->condition('bot', $bot->id());
    }
    $ids = $query->execute();
    $lead = $ids === [] ? NULL : $this->entityTypeManager->getStorage('ai_whatsapp_lead')->load(reset($ids));

    return $lead instanceof ContentEntityInterface ? $lead : NULL;
  }

  /**
   * Adds the contact's new data to an existing lead and tells its people.
   *
   * @return array<string, mixed>
   *   Result with status "lead_updated".
   */
  private function updateLead(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response): array {
    $this->entityTypeManager
      ->getStorage('ai_whatsapp_operator_action')
      ->create([
        'conversation' => $conversation->id(),
        'user' => 1,
        'action' => 'LEAD_UPDATED',
        'note' => 'Lead ID: ' . $lead->id() . "\n\n" . mb_substr(trim($ai_response), 0, 1500),
      ])
      ->save();

    $bot = $lead->get('bot')->entity;
    $notifications = $this->notifyAdministrators(
      $conversation,
      $lead,
      $ai_response,
      $bot instanceof ContentEntityInterface ? $bot : NULL,
      'Actualización del lead ' . $lead->id(),
      'Actualización del lead',
    );

    return [
      'status' => 'lead_updated',
      'lead_id' => $lead->id(),
      'notifications' => $notifications,
    ];
  }

  /**
   * Checks whether this conversation already had a lead handoff.
   */
  private function hasHandoffAction(ContentEntityInterface $conversation): bool {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_operator_action')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->condition('action', 'LEAD_HANDOFF')
      ->range(0, 1)
      ->execute();

    return $ids !== [];
  }

  /**
   * Creates a lead from a conversation.
   */
  private function createLead(ContentEntityInterface $conversation, string $ai_response, ?ContentEntityInterface $bot): ContentEntityInterface {
    // What the contact wrote plus the assistant's closing summary. Earlier
    // assistant messages are left out: their questions carry examples
    // ("ej. juan@tunegocio.com") that were stored as the contact's data.
    $text = $this->recentContactText($conversation) . "\n" . $ai_response;
    $is_web_conversation = $conversation->hasField('provider') && $conversation->get('provider')->value === 'web';
    // WhatsApp providers already deliver the sender's number; web visitors
    // only have a session identifier, so their phone comes from the chat.
    $phone = $is_web_conversation
      ? $this->contactExtractor->extractPhone($text)
      : (string) $conversation->get('phone')->value;
    $name = $this->contactExtractor->extractName($text)
      ?: $this->getFieldValue($conversation, 'name');
    if ($name === '' || $name === 'Web visitor') {
      $name = self::UNKNOWN_CONTACT_NAME;
    }

    $lead = $this->entityTypeManager
      ->getStorage('ai_whatsapp_lead')
      ->create([
        'name' => $name,
        'phone' => $phone,
        'email' => $this->contactExtractor->extractEmail($text),
        // The origin is stored on the lead itself: inferring it later from
        // audit notes or phone numbers attributed leads to the wrong bot.
        'conversation' => $conversation->id(),
        'bot' => $bot?->id(),
        'source' => $is_web_conversation ? 'web' : 'whatsapp',
        'status' => 'qualified',
        'tags' => ['ai-handoff', 'quote-request'],
      ]);
    $lead->save();

    return $lead;
  }

  /**
   * Creates a handoff audit record.
   */
  private function auditHandoff(ContentEntityInterface $conversation, ContentEntityInterface $lead): void {
    $this->entityTypeManager
      ->getStorage('ai_whatsapp_operator_action')
      ->create([
        'conversation' => $conversation->id(),
        'user' => 1,
        'action' => 'LEAD_HANDOFF',
        'note' => 'Lead ID: ' . $lead->id(),
      ])
      ->save();
  }

  /**
   * Notifies the people in charge by WhatsApp and by e-mail.
   *
   * Both channels always go: one failing or not configured never stops the
   * other (decided by the team, 2026-10-21).
   *
   * @return array<int, array<string, mixed>>
   *   Delivery results of both channels.
   */
  private function notifyAdministrators(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, ?ContentEntityInterface $bot, string $heading = 'Nuevo lead de WhatsApp', string $event = 'Nuevo lead'): array {
    return array_merge(
      $this->notifyByWhatsApp($conversation, $lead, $ai_response, $bot, $heading, $event),
      $this->emailNotifier->notify($conversation, $lead, $ai_response, $bot, $event),
    );
  }

  /**
   * Sends WhatsApp notifications to configured administrator numbers.
   *
   * @return array<int, array<string, mixed>>
   *   Delivery results.
   */
  private function notifyByWhatsApp(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, ?ContentEntityInterface $bot, string $heading, string $event): array {
    $account = $this->notificationAccount($conversation, $bot);
    $numbers = $this->notificationNumbers($bot, $account);
    if ($numbers === []) {
      return [];
    }

    $message = $this->buildNotificationMessage($conversation, $lead, $ai_response, $heading);
    if (!$account instanceof ContentEntityInterface) {
      $this->logger->warning('Lead handoff for conversation @conversation has recipients but no WhatsApp account for delivery.', [
        '@conversation' => (string) $conversation->id(),
      ]);
      return [];
    }

    $account_phone = $this->getFieldValue($account, 'phone_number');
    $provider = $this->getFieldValue($account, 'provider');
    $template = $this->notificationTemplate($account, $bot);
    $results = [];

    foreach ($numbers as $number) {
      $recipient = [
        'phone' => $number,
        'account_phone' => $account_phone,
        'whatsapp_account_id' => $account->id(),
      ];
      $results[] = $provider === 'twilio' && $template['sid'] !== ''
        ? $this->messageSender->sendTemplate($provider, $recipient, $template['sid'], $this->notificationTemplateVariables($conversation, $lead, $ai_response, $bot, $template['variables'], $event))
        : $this->messageSender->sendText($provider, $recipient, $message);
    }

    // Same line as the e-mail notice, so the log says whether it went out.
    // A rejected send is logged as an error by ProviderMessageSenderService.
    $this->logger->notice('Lead @lead WhatsApp notice for conversation @conversation: @sent of @count sent.', [
      '@lead' => (string) $lead->id(),
      '@conversation' => (string) $conversation->id(),
      '@sent' => (string) count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? '') === 'sent')),
      '@count' => (string) count($results),
    ]);

    return $results;
  }

  /**
   * Resolves the lead template and its mapping by bot, account, then global.
   *
   * @return array{sid: string, variables: string}
   *   Template content SID and its optional variable mapping.
   */
  private function notificationTemplate(?ContentEntityInterface $account, ?ContentEntityInterface $bot): array {
    // The bot first: area bots share one WhatsApp account, and each area has
    // its own template; an account template must not override them all.
    // Same order as the notification numbers (bot, account, global).
    foreach ([$bot, $account] as $source) {
      $sid = $this->getFieldValue($source, 'lead_notification_template_sid');
      if ($sid !== '') {
        return [
          'sid' => $sid,
          'variables' => $this->getFieldValue($source, 'lead_notification_template_variables'),
        ];
      }
    }

    return [
      'sid' => trim((string) $this->configFactory
        ->get('ai_whatsapp_automation.settings')
        ->get('twilio.content_template_sid')),
      'variables' => '',
    ];
  }

  /**
   * Builds variables for the approved lead-notification template.
   *
   * @return array<string, string>
   *   Values keyed by the placeholders configured for the selected template.
   */
  private function notificationTemplateVariables(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, ?ContentEntityInterface $bot, string $mapping, string $event = 'Nuevo lead'): array {
    $base_url = rtrim((string) ($GLOBALS['base_url'] ?? ''), '/');
    $values = [
      // What happened: a new lead or data added to one. One template serves
      // both, so the person in charge can tell them apart.
      'event' => $this->templateVariable($event, 'Nuevo lead'),
      'lead_id' => $this->templateVariable((string) $lead->id(), 'Sin folio'),
      'contact' => $this->templateVariable((string) $lead->label(), 'Contacto no capturado'),
      'phone' => $this->templateVariable((string) ($lead->get('phone')->value ?: ''), 'No capturado'),
      'email' => $this->templateVariable((string) ($lead->get('email')->value ?: ''), 'No capturado'),
      'summary' => $this->templateSummary($ai_response),
      'bot_name' => $this->templateVariable($bot?->label() ?? '', 'Bot no identificado'),
      'source' => $this->templateVariable($this->getFieldValue($conversation, 'provider'), 'whatsapp'),
      'conversation_url' => $this->templateVariable($base_url . '/admin/content/ai-whatsapp/conversations/' . $conversation->id(), 'No disponible'),
    ];

    $variables = [];
    foreach ($this->templateVariableMapping($mapping) as $placeholder => $value_key) {
      $variables[$placeholder] = $values[$value_key];
    }

    return $variables;
  }

  /**
   * Parses a template variable map or returns the standard lead map.
   *
   * @return array<string, string>
   *   Placeholder numbers keyed to available lead values.
   */
  private function templateVariableMapping(string $mapping): array {
    $default = [
      '1' => 'lead_id',
      '2' => 'contact',
      '3' => 'phone',
      '4' => 'email',
      '5' => 'summary',
    ];
    $mapping = trim($mapping);
    if ($mapping === '') {
      return $default;
    }

    $allowed = array_fill_keys([
      'event',
      'lead_id',
      'contact',
      'phone',
      'email',
      'summary',
      'bot_name',
      'source',
      'conversation_url',
    ], TRUE);
    $parsed = [];
    foreach (preg_split('/\R/u', $mapping) ?: [] as $line) {
      if (!preg_match('/^\s*(\d+)\s*=\s*([a-z_]+)\s*$/', $line, $matches)) {
        continue;
      }
      if (isset($allowed[$matches[2]])) {
        $parsed[$matches[1]] = $matches[2];
      }
    }

    return $parsed !== [] ? $parsed : $default;
  }

  /**
   * Normalizes a Twilio ContentVariables value to a single safe text line.
   */
  private function templateVariable(string $value, string $fallback): string {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    $value = mb_substr($value, 0, 500);

    return $value !== '' ? $value : $fallback;
  }

  /**
   * Creates a plain-text, operator-friendly summary for a Twilio template.
   */
  private function templateSummary(string $summary): string {
    $summary = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $summary) ?? '';
    $summary = str_replace(['**', '__', '`'], '', $summary);
    $summary = str_replace(['•', '▪', '◦'], '|', $summary);
    $summary = preg_replace('/\R+/u', ' | ', $summary) ?? '';
    $summary = preg_replace('/\s*\|\s*/u', ' | ', $summary) ?? '';

    return $this->templateVariable($summary, 'Solicitud de seguimiento generada.');
  }

  /**
   * Builds the notification message sent to administrators.
   */
  private function buildNotificationMessage(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, string $heading = 'Nuevo lead de WhatsApp'): string {
    $base_url = rtrim((string) ($GLOBALS['base_url'] ?? ''), '/');
    $conversation_url = $base_url . '/admin/content/ai-whatsapp/conversations/' . $conversation->id();

    return trim(implode("\n", [
      $heading,
      '',
      'Lead ID: ' . $lead->id(),
      'Contacto: ' . $lead->label(),
      'Teléfono: ' . ((string) $lead->get('phone')->value ?: 'No capturado'),
      'Correo: ' . ((string) $lead->get('email')->value ?: 'No capturado'),
      '',
      'Resumen del bot:',
      mb_substr(trim($ai_response), 0, 900),
      '',
      'Conversación:',
      $conversation_url,
    ]));
  }

  /**
   * Returns who is notified: the bot's people, the account's, or global.
   *
   * With one bot per area on the same number, each area bot lists its own
   * people; the account and global numbers stay as the fallback.
   *
   * @return string[]
   *   Phone numbers.
   */
  private function notificationNumbers(?ContentEntityInterface $bot, ?ContentEntityInterface $account): array {
    $raw = $this->getFieldValue($bot, 'lead_notification_numbers');
    if ($raw === '') {
      $raw = $this->getFieldValue($account, 'lead_notification_numbers');
    }
    if ($raw === '') {
      $raw = (string) $this->configFactory
        ->get('ai_whatsapp_automation.settings')
        ->get('options.lead_notification_numbers');
    }

    $numbers = preg_split('/[\r\n,]+/', $raw) ?: [];

    return array_values(array_filter(array_map('trim', $numbers)));
  }

  /**
   * Loads the WhatsApp account associated with a conversation.
   */
  private function getAccountForConversation(ContentEntityInterface $conversation): ?ContentEntityInterface {
    if (!$conversation->hasField('whatsapp_account') || $conversation->get('whatsapp_account')->isEmpty()) {
      return NULL;
    }

    $account = $conversation->get('whatsapp_account')->entity;

    return $account instanceof ContentEntityInterface ? $account : NULL;
  }

  /**
   * Returns the bot associated with the conversation account.
   */
  private function getBotForConversation(ContentEntityInterface $conversation): ?ContentEntityInterface {
    if ($conversation->hasField('bot') && !$conversation->get('bot')->isEmpty()) {
      $bot = $conversation->get('bot')->entity;
      if ($bot instanceof ContentEntityInterface) {
        return $bot;
      }
    }

    $account = $this->getAccountForConversation($conversation);
    if (!$account instanceof ContentEntityInterface || !$account->hasField('bot') || $account->get('bot')->isEmpty()) {
      return NULL;
    }

    $bot = $account->get('bot')->entity;

    return $bot instanceof ContentEntityInterface ? $bot : NULL;
  }

  /**
   * Resolves the WhatsApp account used to notify administrators.
   */
  private function notificationAccount(ContentEntityInterface $conversation, ?ContentEntityInterface $bot): ?ContentEntityInterface {
    $conversation_account = $this->getAccountForConversation($conversation);
    if ($conversation_account instanceof ContentEntityInterface) {
      return $conversation_account;
    }

    // An area bot reached from a reception menu has no account of its own:
    // web chat leads go out through the reception's account.
    $reception = $conversation->hasField('reception_bot') ? $conversation->get('reception_bot')->entity : NULL;
    foreach ([$bot, $reception] as $candidate) {
      if ($candidate instanceof ContentEntityInterface) {
        $account = $this->botNotificationAccount($candidate);
        if ($account instanceof ContentEntityInterface) {
          return $account;
        }
      }
    }

    return NULL;
  }

  /**
   * Returns the bot's notification account, or its only active account.
   */
  private function botNotificationAccount(ContentEntityInterface $bot): ?ContentEntityInterface {
    if ($bot->hasField('lead_notification_account') && !$bot->get('lead_notification_account')->isEmpty()) {
      $account = $bot->get('lead_notification_account')->entity;
      if ($account instanceof ContentEntityInterface) {
        return $account;
      }
    }

    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_account')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('bot', $bot->id())
      ->condition('status', 'active')
      ->range(0, 2)
      ->execute();
    if (count($ids) !== 1) {
      return NULL;
    }

    $account = $this->entityTypeManager
      ->getStorage('ai_whatsapp_account')
      ->load(reset($ids));

    return $account instanceof ContentEntityInterface ? $account : NULL;
  }

  /**
   * Reads a scalar field value from an entity.
   */
  private function getFieldValue(?ContentEntityInterface $entity, string $field_name): string {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

}
