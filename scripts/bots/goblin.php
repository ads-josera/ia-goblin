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
 * below; anything else edited in the UI (daily limits and budget, allowed
 * domains, notification numbers) is left as it is.
 */

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;

$client_name = 'Goblin';
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
 * Area bots, in menu order. Keywords send a contact straight to the area
 * when exactly one area matches; "cancelar mi hosting" matches both and gets
 * the menu, which is better than guessing.
 */
$areas = [
  'billing' => [
    'name' => 'Goblin Facturación',
    'prompt' => 'facturacion.md',
    'description' => 'Goblin Creative, área de facturación y atención comercial: pagos, facturas, renovaciones, cancelaciones, cotizaciones y contacto con un asesor.',
    'menu_label' => 'Facturación y atención comercial',
    'menu_keywords' => 'factura, facturas, facturacion, pago, pagos, pagar, cobro, cotizacion, cotizar, precio, precios, asesor, cancelar, cancelacion, suspendida',
    'categories' => 'Facturación y atención comercial',
  ],
  'support' => [
    'name' => 'Goblin Soporte',
    'prompt' => 'soporte.md',
    'description' => 'Goblin Creative, área de soporte técnico: correo, cPanel / hosting, dominios y DNS. Resuelve primer nivel y canaliza con el equipo técnico.',
    'menu_label' => 'Soporte técnico',
    'menu_keywords' => 'soporte, correo, correos, outlook, gmail, smtp, imap, cpanel, dominio, dns, ssl',
    'categories' => 'Correo electrónico / cPanel y hosting / Dominio y DNS / Otro soporte',
  ],
];

/*
 * Reception. Its prompt is never sent to the AI (a reception only answers
 * with its menu), but the field is required.
 */
$reception_prompt = 'Bot de recepción de Goblin Creative. No llama a la IA: responde con el menú de áreas y pasa la conversación al bot del área elegida (Goblin Facturación o Goblin Soporte).';
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
  $bot->set('menu_keywords', $area['menu_keywords']);
  $bot->set('handoff_enabled', TRUE);
  $bot->set('handoff_required_fields', $handoff_required_fields);
  $bot->set('handoff_minimum_fields', $handoff_minimum_fields);
  $bot->set('handoff_prompt_rules', str_replace('{categories}', $area['categories'], $handoff_prompt_rules));
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
