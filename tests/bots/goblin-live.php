<?php

/**
 * @file
 * Live test of the Goblin bot against the real OpenAI API.
 *
 * Same steps as WebhookProcessorService for each message, except the
 * provider send: save incoming, AI only while AI_ACTIVE, lead handoff check.
 * Scenarios: goblin-live.json. It spends OpenAI tokens and writes
 * conversations and leads, so run it on a snapshot:
 *
 *   ddev snapshot --name antes-de-probar
 *   ddev drush php:script tests/bots/goblin-live.php > resultado.json
 *   ONLY=2,7 ddev exec 'cd /var/www/html && vendor/bin/drush php:script tests/bots/goblin-live.php'
 *   ddev snapshot restore antes-de-probar
 */
\Drupal::configFactory()->getEditable('ai_whatsapp_automation.settings')->set('options.enable_lead_notifications', TRUE)->save();
$etm = \Drupal::entityTypeManager();
$engine = \Drupal::service('ai_whatsapp_automation.conversation_engine');
$handoff = \Drupal::service('ai_whatsapp_automation.lead_handoff');
$bot = current($etm->getStorage('ai_whatsapp_bot')->loadByProperties(['name' => 'Goblin']));

$scenarios = json_decode(file_get_contents(__DIR__ . '/goblin-live.json'), TRUE);
$only = getenv('ONLY');
$out = [];
foreach ($scenarios as $i => $s) {
  if ($only !== FALSE && $only !== '' && !in_array((string) $i, explode(',', $only), TRUE)) {
    continue;
  }
  $conversation = $etm->getStorage('ai_whatsapp_conversation')->create([
    'client' => $bot->get('client')->target_id,
    'phone' => ($s['provider'] ?? 'evolution') === 'web' ? 'web:3:sesion' . $i : '+5215590000' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
    'name' => ($s['provider'] ?? 'evolution') === 'web' ? 'Web visitor' : '🦊 Perfil ' . $i,
    'channel' => ($s['provider'] ?? 'evolution') === 'web' ? 'web' : 'whatsapp',
    'provider' => $s['provider'] ?? 'evolution',
    'status' => 'AI_ACTIVE',
    'bot' => $bot->id(),
  ]);
  $conversation->save();
  $log = ['scenario' => $s['name'], 'turns' => []];
  foreach ($s['turns'] as $text) {
    $incoming = $etm->getStorage('ai_whatsapp_message')->create(['conversation' => $conversation->id(), 'sender' => 'contact', 'content' => $text]);
    $incoming->save();
    $conversation = $etm->getStorage('ai_whatsapp_conversation')->load($conversation->id());
    if ($conversation->get('status')->value !== 'AI_ACTIVE') {
      $log['turns'][] = ['cliente' => $text, 'bot' => '(sin respuesta de IA: conversación en ' . $conversation->get('status')->value . ')'];
      continue;
    }
    $t = microtime(TRUE);
    try {
      $result = $engine->processSavedIncomingMessage($conversation, $incoming);
      $reply = (string) $result['response_text'];
      $h = $handoff->handle($etm->getStorage('ai_whatsapp_conversation')->load($conversation->id()), $reply);
    }
    catch (\Throwable $e) {
      $reply = 'ERROR: ' . $e->getMessage();
      $h = ['status' => 'error'];
    }
    $log['turns'][] = ['cliente' => $text, 'bot' => $reply, 'lead' => $h['status'], 'segundos' => round(microtime(TRUE) - $t, 1)];
  }
  $leads = $etm->getStorage('ai_whatsapp_lead')->loadByProperties(['conversation' => $conversation->id()]);
  $log['leads'] = array_values(array_map(fn ($l) => ['nombre' => $l->get('name')->value, 'tel' => $l->get('phone')->value, 'email' => $l->get('email')->value, 'estado' => $l->get('status')->value], $leads));
  $log['estado_final'] = $etm->getStorage('ai_whatsapp_conversation')->load($conversation->id())->get('status')->value;
  $out[$i] = $log;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
