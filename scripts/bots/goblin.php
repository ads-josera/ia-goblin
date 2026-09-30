<?php

/**
 * @file
 * Creates or updates the "Goblin" bot of the Goblin client.
 *
 * Bots are content, not configuration: they do not travel with config/sync.
 * This script is how the bot reaches every environment, from the prompt kept
 * in git (docs/bots/goblin/prompt.md):
 *
 *   drush php:script scripts/bots/goblin.php
 *
 * Idempotent: finds the bot by name and client and updates only the fields
 * below; anything else edited in the UI (usage limits and budget, allowed
 * domains, notifications) is left as it is.
 */

declare(strict_types=1);

$client_name = 'Goblin';
$bot_name = 'Goblin';
$prompt_file = DRUPAL_ROOT . '/../docs/bots/goblin/prompt.md';

/*
 * How a lead is detected (LeadHandoffService::isLeadReady): a contact, at
 * least N of these signal groups, and a closing phrase from the bot. Keyword
 * signals cannot recognise a name ("Juan Pérez"), so the rules below make the
 * bot close every handoff with a labelled summary: its labels are the
 * signals, and "Datos capturados" is a closing phrase the module knows.
 * While the bot is still asking for data, the summary does not exist yet, so
 * no lead is created early.
 */
$handoff_required_fields = implode("\n", [
  'nombre',
  'teléfono, telefono, whatsapp, celular',
  'categoría, categoria',
  'descripción, descripcion',
]);
$handoff_minimum_fields = 3;
$handoff_prompt_rules = <<<'RULES'
Reglas de canalización de Goblin (cómo se registra un lead):

- Cuando ya tengas los datos mínimos para escalar un caso (secciones 10 y 11: al menos el nombre y un medio de contacto; en soporte, también la descripción del problema), responde en un solo mensaje con este formato:

Datos capturados:
👤 Nombre: …
📱 Teléfono / WhatsApp: …
✉️ Correo: …
🌐 Dominio o sitio: …
🗂️ Categoría: Facturación y atención comercial / Correo electrónico / cPanel y hosting / Dominio y DNS / Otro soporte
📝 Descripción: resumen con el contexto completo de la conversación (sección 13)

Listo. Ya tengo la información necesaria para canalizar tu solicitud con nuestro equipo.

- Omite las líneas de datos que el cliente no dio; nunca las inventes.
- Escribe «Datos capturados:» únicamente en ese momento, nunca mientras todavía estás pidiendo datos.

Estas reglas tienen prioridad sobre los ejemplos del prompt:

- Nombre y número. En WhatsApp (Provider distinto de "web") ya tienes el número del cliente (Phone del contexto): no lo pidas y úsalo en el resumen. El campo "Name" del contexto es el nombre del perfil de WhatsApp y puede ser un apodo: no lo uses como nombre del cliente. Pregunta «¿A nombre de quién registro la solicitud?» una sola vez y solo cuando ya decidiste canalizar el caso (nunca al inicio ni mientras todavía estás entendiendo o resolviendo el problema), salvo que el cliente ya haya dicho su nombre en esta conversación. En el chat web (Provider "web") no tienes número: pide nombre y número de celular.
- No escribas «Datos capturados:» sin un nombre dado por el cliente.
- Después de «Datos capturados», no hagas ninguna pregunta: la conversación pasa a una persona del equipo y ya no podrás leer la respuesta.
- Si el cliente comparte una contraseña, código o token: no lo repitas ni lo incluyas en el resumen (tampoco el usuario). Dile en tu respuesta que no es necesario compartirla por chat y que le recomiendas cambiarla.
- No ofrezcas enviar precios, planes, promociones ni información que no esté en este prompt o en la base de conocimiento. Si preguntan precios o planes, canaliza con un asesor comercial.
- No inventes direcciones, URLs, servidores ni rutas (por ejemplo «webmail.empresa.com»); di «el webmail de tu dominio» o pregunta.
- No menciones al cliente los datos internos del contexto (nombre del perfil, identificadores).
RULES;

/*
 * Web chat identity. The primary color paints the visitor's bubbles with
 * white normal-size text, so it must reach 4.5:1: the logo blue #065885
 * gives 7.66:1; the brand orange (3.16:1) would not be readable there.
 * The welcome is the prompt's own (section 2), shown before the first reply.
 */
$web_widget = [
  'web_widget_enabled' => TRUE,
  'web_widget_assistant_name' => 'Goblin',
  'web_widget_language' => 'es',
  'web_widget_primary_color' => '#065885',
  'web_widget_welcome_message' => "👋 ¡Bienvenido a Goblin Creative!\n\nSi buscas información, una cotización, asesoría sobre nuestros servicios o necesitas soporte, estoy listo para ayudarte.\n\nCuéntame qué necesitas o selecciona una opción:\n\n1️⃣ Facturación y atención comercial\n2️⃣ Soporte técnico\n\nTambién puedes escribirme directamente qué problema tienes y yo te ayudaré a identificarlo.",
];

$etm = \Drupal::entityTypeManager();
$prompt = trim((string) @file_get_contents($prompt_file));
if ($prompt === '') {
  throw new \RuntimeException("Prompt not found or empty: $prompt_file");
}

$clients = $etm->getStorage('ai_whatsapp_client')->loadByProperties(['name' => $client_name]);
$client = reset($clients);
if (!$client) {
  throw new \RuntimeException("Client \"$client_name\" does not exist. Create it first.");
}

$bots = $etm->getStorage('ai_whatsapp_bot')->loadByProperties(['name' => $bot_name, 'client' => $client->id()]);
$bot = reset($bots);
$created = !$bot;
if ($created) {
  $bot = $etm->getStorage('ai_whatsapp_bot')->create([
    'name' => $bot_name,
    'client' => $client->id(),
    'model' => 'gpt-5-mini',
    'reasoning_effort' => 'low',
    'status' => 'active',
  ]);
}

$bot->set('description', 'Asistente virtual de Goblin Creative: orienta, resuelve primer nivel (correo, cPanel/hosting, dominios/DNS, facturación) y canaliza con el equipo.');
$bot->set('system_prompt', $prompt);
$bot->set('handoff_enabled', TRUE);
$bot->set('handoff_required_fields', $handoff_required_fields);
$bot->set('handoff_minimum_fields', $handoff_minimum_fields);
$bot->set('handoff_prompt_rules', $handoff_prompt_rules);
foreach ($web_widget as $field => $value) {
  $bot->set($field, $value);
}

$violations = $bot->validate();
if ($violations->count() > 0) {
  foreach ($violations as $violation) {
    echo 'Invalid: ' . $violation->getPropertyPath() . ': ' . $violation->getMessage() . PHP_EOL;
  }
  throw new \RuntimeException('The bot was not saved.');
}
$bot->save();

printf("%s bot \"%s\" (id %d) for client \"%s\" — prompt %d characters.\n", $created ? 'Created' : 'Updated', $bot->label(), $bot->id(), $client->label(), mb_strlen($prompt));
