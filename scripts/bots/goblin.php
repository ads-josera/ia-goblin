<?php

/**
 * @file
 * Creates or updates the Goblin client's bots: a reception and one per area.
 *
 * One WhatsApp number and one chat link: the "Goblin" bot is a reception that
 * answers with a numbered menu (no AI) and hands the conversation to the bot
 * of the chosen area. Each area bot has its own prompt, lead rules and people
 * to notify. See docs/bots/goblin/README.md.
 *
 * Bots are content, not configuration: they do not travel with config/sync.
 * This script is how they reach every environment, from the prompts kept in
 * git (docs/bots/goblin/*.md):
 *
 *   drush php:script scripts/bots/goblin.php
 *
 * Idempotent: finds each bot by name and client and updates only the fields
 * below (lead notice templates and e-mails included: change them here, not in
 * the UI); anything else edited in the UI (daily limits and budget, allowed
 * domains, notification numbers) is left as it is.
 */

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;

$client_name = 'Goblin';
$client_brand = 'Goblin Creative';
$prompt_dir = DRUPAL_ROOT . '/../docs/bots/goblin';

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
🗂️ Categoría: {categories}
📝 Descripción: resumen con el contexto completo de la conversación (sección 13). Si antes el cliente planteó otro problema y no confirmó que quedó resuelto, termina con «Además: …».

Listo. Ya tengo la información necesaria para canalizar tu solicitud con nuestro equipo.

- Omite las líneas de datos que el cliente no dio; nunca las inventes.
- Escribe «Datos capturados:» únicamente en ese momento, nunca mientras todavía estás pidiendo datos.

Estas reglas tienen prioridad sobre los ejemplos del prompt:

- Otra área. Si el cliente pide algo que no es de tu área (según el inicio de tu prompt), no lo atiendas, no pidas datos y no lo canalices tú: responde en un mensaje corto que escriba «menú» y elija el área que corresponde, y nada más: sin preguntas, consejos ni promesas de contacto. Ahí lo atienden.
- Brevedad. Cada respuesta lleva como máximo 2 preguntas y unas 4 líneas cortas: pregunta lo más importante primero y lo demás en el siguiente mensaje. El bloque «Datos capturados» o «Datos adicionales» no cuenta para ese límite.
- Primer mensaje del área. Si el cliente acaba de elegir el área en el menú, salúdalo y hazle una sola pregunta abierta sobre lo que necesita. No pidas todavía nombre, número ni correo.

- Nombre y número. En WhatsApp (Provider distinto de "web") ya tienes el número del cliente (Phone del contexto): no lo pidas y úsalo en el resumen. El campo "Name" del contexto es el nombre del perfil de WhatsApp y puede ser un apodo: no lo uses como nombre del cliente. Tampoco deduzcas un nombre de su correo, dominio o usuario («josera@…» no dice que se llame José): no lo llames por ningún nombre que no te haya dicho. Pregunta «¿A nombre de quién registro la solicitud?» solo cuando ya decidiste canalizar el caso y ya tienes lo necesario del problema, y como máximo dos veces en toda la conversación. Si el cliente pide hablar con un asesor o una persona, pregúntale el motivo como mucho una vez; si no lo da, canaliza igual. Nunca la pongas en el mismo mensaje que una pregunta sobre el problema: si en lugar de responder sigue contando su problema, atiende eso y vuelve a pedir el nombre una sola vez, al cerrar (nunca al inicio ni mientras todavía estás entendiendo o resolviendo el problema), salvo que el cliente ya haya dicho su nombre en esta conversación. En el chat web (Provider "web") no tienes número: pide nombre y número de celular.
- No escribas «Datos capturados:» sin un nombre dado por el cliente.
- En WhatsApp, después de «Datos capturados» no hagas ninguna pregunta: la conversación pasa a una persona del equipo y ya no podrás leer la respuesta. En el chat web (Provider "web") sigues atendiendo al cliente después del resumen.
- Casos ya registrados (los ves en «Cases already registered» del contexto). Si el cliente da más datos de un caso ya registrado en tu área, responde y cierra con un bloque «Datos adicionales:» que resuma solo lo nuevo: así se agregan a ese caso y se avisa al equipo. Si es un problema distinto, atiéndelo y, si hay que canalizarlo, cierra con «Datos capturados:» como un caso nuevo. Nunca digas que agregas, adjuntas, envías o registras algo si no cierras con uno de esos dos bloques.
- Si el cliente comparte una contraseña, código o token: no lo repitas ni lo incluyas en el resumen (tampoco el usuario). Dile en tu respuesta que no es necesario compartirla por chat y que le recomiendas cambiarla.
- No ofrezcas enviar precios, planes, promociones ni información que no esté en este prompt o en la base de conocimiento. Si preguntan precios o planes, canaliza con un asesor comercial.
- No ofrezcas revisar, consultar ni verificar nada por tu cuenta (DNS, registros MX, servidores, cuentas o pagos): no tienes acceso a ningún sistema. Explica cómo puede revisarlo el cliente o canaliza el caso con el equipo.
- No inventes direcciones, URLs, servidores ni rutas (por ejemplo «webmail.empresa.com»); di «el webmail de tu dominio» o pregunta. La única excepción son los datos de configuración de correo que vienen en tu prompt (si los tiene).
- No menciones al cliente los datos internos del contexto (nombre del perfil, identificadores).
RULES;

/*
 * Area bots, in menu order (decided by the team: 1 Ventas, 2 Soporte,
 * 3 Facturación). Keywords send a contact straight to the area when exactly
 * one area matches; "cancelar mi hosting" matches two and gets the menu,
 * which is better than guessing. Sales only quotes new development projects
 * and never gives prices.
 */
$areas = [
  'sales' => [
    'name' => 'Goblin Ventas',
    // WhatsApp template for lead notices (scripts/twilio/plantillas_goblin.py),
    // approved by WhatsApp 2026-10-21. Empty = plain-text notices.
    'template_sid' => 'HXf5e01996925cd413936b8c42b433e5dd',
    // Lead notices by e-mail, in addition to WhatsApp (team, 2026-10-21).
    'notification_emails' => 'kevinyssen@goblincreative.com',
    'greeting' => '👋 Hola, estás hablando con el asistente virtual de Ventas de Goblin Creative. ¿Qué proyecto te gustaría cotizar?',
    'prompt' => 'ventas.md',
    'description' => 'Goblin Creative, área de ventas: cotizaciones de proyectos nuevos de desarrollo (HTML5 y PHP, Drupal y WordPress, tiendas en línea, sistemas personalizados). Nunca da precios: el área de ventas contacta al cliente.',
    'menu_label' => 'Ventas y cotizaciones',
    // Only words that mean "something new": "sitio web", "WordPress" or
    // "sistema" also show up in support problems ("mi sitio web no carga").
    'menu_keywords' => 'cotizacion, cotizar, cotizaciones, ventas, tienda en linea, tienda online, nuevo sitio, nueva pagina, desarrollar',
    'categories' => 'Cotización: Desarrollo HTML5 y PHP / Drupal o WordPress / Tienda en línea / Sistema personalizado',
  ],
  'support' => [
    'name' => 'Goblin Soporte',
    // WhatsApp template for lead notices (scripts/twilio/plantillas_goblin.py),
    // approved by WhatsApp 2026-10-21. Empty = plain-text notices.
    'template_sid' => 'HX6ef6d0cedace2e7194030a2ff597ec1a',
    // Lead notices by e-mail, in addition to WhatsApp (team, 2026-10-21).
    'notification_emails' => 'soporte@goblincreative.com',
    'greeting' => '👋 Hola, estás hablando con el asistente virtual de Soporte técnico de Goblin Creative. ¿En qué te puedo ayudar?',
    'prompt' => 'soporte.md',
    'description' => 'Goblin Creative, área de soporte técnico: correo, cPanel / hosting, dominios y DNS. Resuelve primer nivel y canaliza con el equipo técnico.',
    'menu_label' => 'Soporte técnico',
    'menu_keywords' => 'soporte, correo, correos, outlook, gmail, smtp, imap, cpanel, dominio, dns, ssl',
    'categories' => 'Correo electrónico / cPanel y hosting / Dominio y DNS / Otro soporte',
  ],
  'billing' => [
    'name' => 'Goblin Facturación',
    // WhatsApp template for lead notices (scripts/twilio/plantillas_goblin.py),
    // approved by WhatsApp 2026-10-21. Empty = plain-text notices.
    'template_sid' => 'HXffbfb1e9d55c5d2f4f7bfef9b367cf30',
    // Lead notices by e-mail, in addition to WhatsApp (team, 2026-10-21).
    'notification_emails' => 'kevinyssen@goblincreative.com',
    'greeting' => '👋 Hola, estás hablando con el asistente virtual de Facturación y atención comercial de Goblin Creative. ¿En qué te puedo ayudar?',
    'prompt' => 'facturacion.md',
    'description' => 'Goblin Creative, área de facturación y atención comercial: pagos, facturas, renovaciones, cancelaciones, contratación de hosting, correo y dominios, y contacto con un asesor.',
    'menu_label' => 'Facturación y atención comercial',
    'menu_keywords' => 'factura, facturas, facturacion, pago, pagos, pagar, cobro, precio, precios, asesor, cancelar, cancelacion, suspendida',
    'categories' => 'Facturación y atención comercial',
  ],
];

/*
 * Variables of the area templates (scripts/twilio/plantillas_goblin.py):
 * {{1}} kind of notice, {{2}} lead, {{3}} contact, {{4}} phone, {{5}} email,
 * {{6}} summary, {{7}} conversation link.
 */
$template_variables = implode("\n", [
  '1=event',
  '2=lead_id',
  '3=contact',
  '4=phone',
  '5=email',
  '6=summary',
  '7=conversation_url',
]);

/*
 * Reception. Its prompt is never sent to the AI (a reception only answers
 * with its menu), but the field is required.
 */
$reception_prompt = 'Bot de recepción de Goblin Creative. No llama a la IA: responde con el menú de áreas y pasa la conversación al bot del área elegida (Goblin Ventas, Goblin Soporte o Goblin Facturación).';
$reception_menu_message = "👋 ¡Bienvenido a Goblin Creative!\n\nSi buscas información, una cotización, asesoría sobre nuestros servicios o necesitas soporte, estoy listo para ayudarte.\n\nSelecciona una opción:";

/*
 * Web chat identity, on the reception (the chat link stays the same). The
 * primary color paints the visitor's bubbles with white normal-size text, so
 * it must reach 4.5:1: the logo blue #065885 gives 7.66:1; the brand orange
 * (3.16:1) would not be readable there.
 */
$web_widget = [
  'web_widget_enabled' => TRUE,
  'web_widget_assistant_name' => 'Goblin',
  'web_widget_language' => 'es',
  'web_widget_primary_color' => '#065885',
  // A normal support case took 9 messages; the default 8 per 15 minutes cut
  // customers off mid-problem. Daily conversations and budget still cap cost.
  'web_widget_message_limit' => 20,
];

$etm = \Drupal::entityTypeManager();
// Validation checks that referenced bots (the menu) can be seen by the
// current user; drush runs as anonymous. Act as the site administrator, as
// someone editing the bots in the admin would.
\Drupal::service('account_switcher')->switchTo($etm->getStorage('user')->load(1));
$read_prompt = static function (string $file) use ($prompt_dir): string {
  $prompt = trim((string) @file_get_contents($prompt_dir . '/' . $file));
  if ($prompt === '') {
    throw new \RuntimeException("Prompt not found or empty: $prompt_dir/$file");
  }
  return $prompt;
};
$common = $read_prompt('comun.md');

// A fresh install (drush si --existing-config) has no clients: they are
// content. The bots need their client, so create it the first time.
$clients = $etm->getStorage('ai_whatsapp_client')->loadByProperties(['name' => $client_name]);
$client = reset($clients);
if (!$client) {
  $client = $etm->getStorage('ai_whatsapp_client')->create(['name' => $client_name, 'status' => 'active']);
  $client->save();
  echo "Created client \"$client_name\" (id {$client->id()})." . PHP_EOL;
}
// Heads the lead notice e-mails sent to the team.
if ($client->get('brand_name')->value !== $client_brand) {
  $client->set('brand_name', $client_brand)->save();
}

$load_or_create = static function (string $name) use ($etm, $client): array {
  $bots = $etm->getStorage('ai_whatsapp_bot')->loadByProperties(['name' => $name, 'client' => $client->id()]);
  $bot = reset($bots);
  if ($bot) {
    return [$bot, FALSE];
  }
  return [$etm->getStorage('ai_whatsapp_bot')->create([
    'name' => $name,
    'client' => $client->id(),
    'model' => 'gpt-5-mini',
    'reasoning_effort' => 'low',
    'status' => 'active',
  ]), TRUE];
};
$save = static function (ContentEntityInterface $bot, bool $created, string $detail) use ($client): void {
  $violations = $bot->validate();
  if ($violations->count() > 0) {
    foreach ($violations as $violation) {
      echo 'Invalid: ' . $violation->getPropertyPath() . ': ' . $violation->getMessage() . PHP_EOL;
    }
    throw new \RuntimeException('The bot "' . $bot->label() . '" was not saved.');
  }
  $bot->save();
  printf("%s bot \"%s\" (id %d) for client \"%s\" — %s.\n", $created ? 'Created' : 'Updated', $bot->label(), $bot->id(), $client->label(), $detail);
};

$area_ids = [];
foreach ($areas as $area) {
  [$bot, $created] = $load_or_create($area['name']);
  $prompt = $common . "\n\n⸻\n\n" . $read_prompt($area['prompt']);
  $bot->set('description', $area['description']);
  $bot->set('system_prompt', $prompt);
  $bot->set('menu_label', $area['menu_label']);
  // Sent as is when the area is picked by its number (no model call).
  $bot->set('menu_greeting', $area['greeting']);
  $bot->set('menu_keywords', $area['menu_keywords']);
  // "low", not "minimal": minimal answered in half the time but broke the
  // out-of-area rule (Support and Billing started quoting) in a live
  // regression, 2026-10-12. The brevity rule is what speeds low up.
  $bot->set('reasoning_effort', 'low');
  $bot->set('handoff_enabled', TRUE);
  $bot->set('handoff_required_fields', $handoff_required_fields);
  $bot->set('handoff_minimum_fields', $handoff_minimum_fields);
  $bot->set('handoff_prompt_rules', str_replace('{categories}', $area['categories'], $handoff_prompt_rules));
  // Only once the template is approved: with an unapproved SID every notice
  // fails, while plain text at least works inside WhatsApp's 24-hour window.
  if ($area['template_sid'] !== '') {
    $bot->set('lead_notification_template_sid', $area['template_sid']);
    $bot->set('lead_notification_template_variables', $template_variables);
  }
  $bot->set('lead_notification_emails', $area['notification_emails']);
  // Reached through the reception: no chat link of its own.
  $bot->set('web_widget_enabled', FALSE);
  $save($bot, $created, 'prompt ' . mb_strlen($prompt) . ' characters');
  $area_ids[] = $bot->id();
}

[$reception, $created] = $load_or_create('Goblin');
$reception->set('description', 'Recepción de Goblin Creative: muestra el menú de áreas (sin IA) y pasa la conversación al bot del área elegida.');
$reception->set('system_prompt', $reception_prompt);
$reception->set('menu_message', $reception_menu_message);
$reception->set('menu_bots', $area_ids);
// The reception collects no data: leads come from the area bots.
$reception->set('handoff_enabled', FALSE);
foreach ($web_widget as $field => $value) {
  $reception->set($field, $value);
}
// The chat shows the same menu as WhatsApp before the first message.
$reception->set('web_widget_welcome_message', \Drupal::service('ai_whatsapp_automation.menu_router')->menuFor($reception));
$save($reception, $created, 'menu with ' . count($area_ids) . ' areas');
\Drupal::service('account_switcher')->switchBack();
