# Bitácora del proyecto

Registro de lo que se ha hecho, por qué, y lo que queda pendiente. La entrada
más reciente va arriba. Cada entrada separa lo **comprobado** (se ejecutó) de
lo **no comprobado**.

---

## Dónde quedamos

- **En producción:** https://ia.goblincreative.com (HTTPS forzado, cron cada
  5 min, clave de OpenAI guardada desde el admin y a salvo de los
  despliegues).
- **Bots por área en producción y probados (2026-10-05):** «Goblin» es la
  recepción con menú (sin IA); **Goblin Facturación** y **Goblin Soporte**
  atienden cada área. Prueba en el chat web de producción: menú → área →
  «Datos capturados» → **1 lead del bot Goblin Facturación**, sin errores.
- Clave de OpenAI de pruebas en el `settings.local.php` local (fuera de git).
  **Rotarla** (se compartió por chat).
- **Siguiente:**
  1. Números de WhatsApp del encargado de cada área (campo «Lead
     notification WhatsApp numbers» de cada bot de área). Sin ellos los
     avisos no llegan a nadie.
  2. Prompt de **Ventas** con el equipo (se agrega como tercera área del
     menú, sin cambiar código).
  3. Que el equipo revise los encabezados de área de `facturacion.md` y
     `soporte.md`.
  4. Conectar el número de WhatsApp de Goblin (cuenta con el bot «Goblin»).
  5. Chat web después del lead: la IA sigue contestando y promete cosas
     («lo adjunto al caso») que nadie va a leer.

**Por decidir (equipo):**
- Números de WhatsApp del equipo que reciben los avisos de leads.
- Color secundario del chat web: hoy no se usa en ningún sitio (el
  encabezado es un azul fijo). Propuesta: que sea el color del encabezado.
- En el chat web la IA sigue respondiendo después de crear un lead.

## Pendientes

| # | Pendiente | Prioridad |
|---|-----------|-----------|
| 1 | Textos del módulo aún en inglés en algunas pantallas (p. ej. «Evolution QR connections», etiquetas de la ficha del bot: «System prompt», «Model», sección «Reception menu»); de paso, ocultar «Menu areas» en los bots de área | Media |
| 2 | Bots por área: crear Ventas (prompt con el equipo); números de cada encargado en su bot; revisar encabezados de área | Alta |
| 2b | Gráfica «Actividad por día»: con valores pequeños repite la etiqueta del eje (1, 1) | Baja |
| 3b | Definir los números de WhatsApp del equipo que reciben los avisos de leads (los leads ya están activados) | Alta |
| 3c | Producción: borrar `~/ia.goblincreative.com.anterior` cuando ya no haga falta | Media |
| 3d | OpenAI por cliente (hoy hay una clave general; Twilio y Evolution ya son por cuenta) | Futuro |
| 4 | Safari real: lo revisa el equipo. Automatización activada; la sesión se agotaba con Safari abierto. Reintentar con Safari cerrado: `npm run qa:real` | Media |
| 6 | Comprobar `drush site:install --existing-config` en una copia limpia | Media |
| 7 | Añadir `#[LegacyRequirementsHook]` a `ai_whatsapp_automation_requirements()` (deprecado en 11.3, se elimina en 13) | Baja |
| 8 | Tema Goblin: campo hexadecimal junto a cada selector de color | Baja |
| 9 | Un usuario con sesión que abre `/user/login` recibe «acceso denegado» (comportamiento de core); podría redirigirse a su inicio | Baja |

---

## 2026-10-05 — Botón flotante del chat: centrado en cualquier sitio, iconos que funcionan

**Visto por el equipo en goblincreative.com:** el icono del botón flotante
no estaba centrado. **Medido:** corrido 12.5 px a la izquierda. Causa: el tema
del sitio da a todos los `<button>` `padding: 1px 6px` e `inline-block`, y el
icono (un dibujo hecho con cajas CSS) quedaba pegado al borde. Le pasaría lo
mismo en el sitio de cualquier cliente. Además, el campo «Web widget button
icon» del bot (Chat / Sparkles / Help) **no hacía nada**: el script lo
ignoraba.

**Qué se hizo**

- El script del botón pasó de un texto dentro de `WebChatController` a su
  archivo, `js/web-chat-embed.js`.
- Botón y ventana dentro de un **shadow root**: ningún estilo del sitio del
  cliente los alcanza.
- Iconos SVG nuevos y centrados (burbuja moderna, destellos de IA, ayuda) y
  opción nueva **«Logo»**: usa el logo subido en el bot, sobre botón blanco
  (un logo suele tener los colores de la marca, los mismos del botón). Sin
  logo, cae a la burbuja. Actualización `11043` (opción nueva de la lista).
- Abierto, el botón muestra una **X**; `aria-expanded` y nombre accesible
  («Abrir chat con Goblin» / «Cerrar chat»), foco visible,
  `prefers-reduced-motion`.
- El nombre del bot va al script con `JSON_HEX_*`: un `</script>` en el
  nombre no puede romper la página del cliente.

**Comprobado**

- `tests/visual/embed-check.mjs` (nuevo): página **hostil** (estilos agresivos
  sobre button, span, svg, img, iframe); 5 casos de icono: centrado (0 px de
  desvío), 60×60, icono sin deformar, nombre accesible, abre y cierra, sin
  borde ajeno en la ventana. **OK en Chromium, WebKit (Safari) y Firefox.**
- Sabotaje: sin el shadow root, icono de 100 px y 20 px de desvío.
- `WebChatEmbedTest` (funcional, nuevo): el icono, el logo, los textos y el
  escape del nombre llegan al script; falla si se quita el escape o el logo
  (sabotaje hecho).
- 173 pruebas OK; `phpcs` sin observaciones nuevas en PHP; sintaxis JS con
  `node --check` (phpcs no sirve para JS y el proyecto no tiene ESLint).

**No comprobado:** en goblincreative.com (falta desplegar; el sitio carga el
script desde producción, así que se verá al desplegar, sin tocar el sitio).

**Visto, para después:** en el chat web la IA sigue contestando después del
lead y prometió «lo adjunto al caso» (pendiente ya anotado: nadie lee esos
mensajes). El encabezado del chat dice «AI» porque el bot no tiene logo.

---

## 2026-10-05 — Lead creado en producción por un bot de área

**Comprobado en producción** (chat web, ventana privada limpia): «1» →
Goblin Facturación → «quiero cancelar mi hosting» → nombre y celular →
«Datos capturados». Resultado: `Leads: 1`, `Lead 1 -> bot: Goblin
Facturación`, 3 conversaciones (la del 30 de septiembre, la prueba anterior
y esta), registro sin errores. Cierra el pendiente «probar un lead en
producción».

**No comprobado:** el aviso por WhatsApp al encargado (todavía no hay
números ni número de WhatsApp de Goblin conectado).

---

## 2026-10-05 — El bot ofrecía «revisar» cosas a las que no tiene acceso

**Visto en producción y en local:** Soporte ofrecía «revise el MX actual del
dominio» / «puedo revisar los registros MX». No tiene acceso a DNS,
servidores, cuentas ni pagos.

**Qué se hizo:** regla nueva en las reglas de canalización
(`scripts/bots/goblin.php`, aplica a los dos bots de área): no ofrecer
revisar, consultar ni verificar nada por su cuenta; explicar cómo lo revisa
el cliente o canalizar. Texto nuestro, no del prompt maestro.

**Comprobado (en vivo, local, OpenAI real):** sin la regla, 2 de 2 respuestas
a «¿Qué es un registro MX? Mi dominio es rayere.com.mx» ofrecieron revisarlo
(«¿Quieres que lo revise por ti?»). Con la regla, 0 de 3 (MX, página que no
carga, estado de un pago); en el pago dijo «No tengo acceso directo a los
registros de pago». Pocas corridas: el modelo no es determinista. Base local
restaurada sin las conversaciones de prueba.

**También aclarado:** en producción el bot recordó la conversación del 30 de
septiembre porque el navegador conservó la sesión del chat. Es una función
del módulo: si un visitante (o número de WhatsApp) vuelve y su conversación
anterior se cerró por inactividad hace menos de 7 días, la IA recibe un
resumen para no hacerle repetir. Se deja así (decisión del equipo). Para
probar desde cero: cerrar todas las ventanas privadas y abrir una nueva.

---

## 2026-10-05 — Revisión con la cuenta del Gestor; Enrutamiento muestra las áreas

Con la skill Jarvis cargada (acuerdo nuevo: se carga al iniciar cada sesión,
ver `CLAUDE.md`) se recorrieron con la cuenta **demo-gestor** las pantallas
que cambian con los bots de área: lista de bots, formulario de la recepción y
de un área, Enrutamiento, integración web de un área.

**Encontrado y arreglado:** en Enrutamiento, una cuenta con la recepción
decía solo «Goblin»: no se veía que el número reparte a dos áreas ni que la
recepción no usa IA. Ahora la celda del bot dice «Menú: Facturación y
atención comercial · Soporte técnico» y la de modelo «Menú, sin IA».

**Comprobado:** prueba funcional con la cuenta del Gestor (incluye que un
nombre con HTML se muestra como texto); sabotaje (sin el cambio, falla);
captura en escritorio y en móvil (la tabla se desplaza dentro de su
contenedor, la página no); 172 pruebas OK; `phpcs` sin observaciones nuevas.
Cuenta temporal de la captura creada y borrada en local.

**Visto y anotado, sin cambiar:** los textos de la sección «Reception menu»
están en inglés como el resto del formulario del bot (pendiente 1); un bot de
área también muestra «Menu areas» vacío (no rompe nada; mejorar cuando se
traduzca el formulario). La integración web de un bot de área dice que su
chat está apagado: correcto, se entra por la recepción.

---

## 2026-10-05 — El «2» del menú se leía como opción de una lista vieja

**Encontrado por el equipo** (en local): en una conversación del 30 de
septiembre que ya tenía una lista numerada donde «2» era «Renovación», el
cliente escribió «2» en el menú. El menú **sí** pasó la conversación a
Soporte, pero Soporte recibió el «2» suelto con ese historial y respondió
sobre «Renovación». Puede pasar en producción con un cliente que vuelve días
después.

**Qué se hizo:** cuando el área se elige por número, el modelo recibe «El
cliente eligió «Soporte técnico» en el menú de áreas…» en lugar del número.
En el historial queda el «2» tal cual (el operador ve lo que escribió el
cliente). Si el área se eligió por palabra clave, el modelo recibe el
mensaje original.

**Comprobado:** prueba nueva en `ReceptionMenuTest` (falla si se quita el
arreglo: sabotaje hecho); en vivo con OpenAI sobre esa misma conversación
(copia restaurada): «menú» → menú; «2» → Soporte saluda y pregunta el tipo de
problema. 172 pruebas OK.

---

## 2026-10-05 — Un bot por área: recepción con menú, Facturación y Soporte

**Petición del equipo:** separar el bot Goblin por áreas en el mismo número
de WhatsApp, con el aviso del lead a cada encargado. Hoy Facturación y
Soporte; Ventas después (su prompt se escribe con el equipo).

**Qué se hizo (módulo)**

- `MenuRouter`: un bot con «Menu areas» es una **recepción**. Responde con un
  menú numerado **sin llamar a la IA** y, cuando el cliente elige (número o
  palabra clave de un solo área), cambia el bot de la conversación al del
  área, que contesta ese mismo mensaje. «menú» como mensaje completo regresa
  a la recepción. Está en `ConversationEngineService`, el punto por donde
  pasan WhatsApp y chat web.
- Campos nuevos del bot: `menu_bots`, `menu_message`, `menu_label`,
  `menu_keywords`, `lead_notification_numbers`; de la conversación:
  `reception_bot`. Actualización `11042`. Sección «Reception menu» en el
  formulario del bot.
- Avisos: números del bot → de la cuenta → generales. Los números de las
  áreas tampoco reciben respuestas de la IA si escriben al número.
- Chat web: los límites diarios y el presupuesto de la recepción cuentan
  también las conversaciones que pasaron a un área.
- Mismo cliente: un menú no puede ofrecer bots de otro cliente (la
  validación ahora revisa campos con varios valores).
- Ruido en la recepción (texto ilegible) sigue la escalera de mensajes
  ilegibles, que se calla después de dos, en lugar de repetir el menú.

**Qué se hizo (contenido)**

- El prompt maestro se separó **sin reescribirlo** en `comun.md`,
  `facturacion.md` y `soporte.md` (mismas secciones con su número original).
  Fuera: 2 y 20 (bienvenida y menú, ahora de la recepción), 3 y 19 (la
  clasificación la hace el menú). Nuevo: un encabezado por área (qué atiende,
  no repetir el menú, «menú» para otro tema). **Pendiente que el equipo lo
  revise.** El original queda como `prompt-maestro-original.md`.
- `scripts/bots/goblin.php` crea o actualiza los tres bots y el menú (corre
  como el usuario 1: la validación de referencias exige poder ver los bots).
  Idempotente: probado dos veces seguidas.
- `tests/bots/goblin-live.*`: cada escenario elige su área primero.

**Comprobado**

- Pruebas nuevas: `ReceptionMenuTest` (kernel, 5) y `ReceptionMenuFormTest`
  (funcional, con la cuenta del **Gestor**: arma el menú desde el formulario
  y no puede poner un bot de otro cliente).
- Sabotaje: 8 cambios rotos a propósito (sin router, avisos sin números del
  bot, límite web sin recepción, encargado tratado como cliente, validación
  solo del primer bot, adivinar con dos áreas, «menú» dentro de una frase,
  sin validación de cliente en el formulario): todos detectados.
- Suites completas (módulo, `goblin_portal`, tema): 172 pruebas OK.
  `phpcs`: ninguna observación nueva en los archivos tocados.
- En vivo con OpenAI (local, copia restaurada): menú, paso a Soporte,
  preguntas de primer nivel, Soporte manda los precios a «menú», regreso al
  menú, Facturación pide datos para cancelar, «necesito mi factura» pasa
  directo. Detalle en `docs/bots/goblin/README.md`.

**Arreglado de paso:** `ActivitySeriesTest` fallaba desde el 4 de octubre:
restaba días como bloques de 86400 s y las pruebas de Drupal corren en hora
de Sídney, que cambió al horario de verano ese día (23 h). La gráfica estaba
bien (usa días de calendario); se corrigió la prueba.

**Desplegado** (`4e6c8c9`): 11042 aplicada, bots creados (Facturación 2, Soporte 3, recepción 1). `web/.htaccess` aparece modificado en el servidor: lo añadió cPanel (handler de PHP 8.4); se deja, ver despliegue.md.

**No comprobado:** el chat en producción; WhatsApp real (la cuenta
de producción todavía no tiene número conectado; probado con Twilio
simulado); la prueba en vivo completa de 12 escenarios con los bots
separados.

---

## 2026-09-30 — Diseño: un bot por área (por hacer)

**Petición del equipo:** un bot de Facturación, uno de Soporte y uno de
Ventas, **en el mismo número de WhatsApp**, y que el aviso de cada lead le
llegue a su encargado.

**Decisiones del equipo**

- Reparto **por menú con números**: la bienvenida muestra 1 Facturación,
  2 Soporte, 3 Ventas; el número (o la palabra) pasa la conversación al bot
  de esa área. Sin costo de IA. Si escribe otra cosa, se repite el menú.
- Cambio de área: cada bot atiende solo lo suyo; si le preguntan otra cosa,
  indica escribir **«menú»**, que regresa la conversación a la recepción.
- Prompt de Ventas: se escribe con el equipo (el prompt actual junta
  «Facturación y atención comercial»).

**Lo que ya existe en el módulo (revisado en el código)**

- La conversación guarda su propio `bot` y `BotManagerService::
  getBotForConversation()` lo usa antes que el de la cuenta: cambiar de bot
  es cambiar ese campo.
- Cada bot tiene sus propias reglas de lead (señales, mínimo, frases,
  plantilla, `lead_notification_account`).

**Lo que falta**

1. Recepción: en el bot asignado a la cuenta, una lista «opción → bot»
   (número y palabras) y la palabra «menú»; antes de llamar a la IA, si el
   mensaje coincide, se cambia `conversation.bot` y responde el especialista.
   Igual en WhatsApp y en el chat web.
2. Números de aviso por bot (`lead_notification_numbers` en el bot), con
   prioridad bot → cuenta → configuración general.
3. Tres prompts cortos (Facturación y Soporte salen del prompt actual;
   Ventas, nuevo) y el script `scripts/bots/goblin.php` para crearlos.
4. Pruebas por rol, sabotaje, prueba en vivo y despliegue.

**Por definir:** número de WhatsApp de cada encargado; qué pasa con el
estado de la conversación al cambiar de área tras un lead (hoy un lead la
pasa a atención humana).

---

## 2026-09-30 — Bot en producción con la clave del admin

**Comprobado en el servidor**

- Clave de OpenAI de producción escrita en la Configuración del módulo: el
  State tiene `openai.api_key` y `\Drupal::config()` la devuelve (sin
  mostrar el valor).
- Chat web: 1 conversación, 8 mensajes, sin errores. El bot sigue el menú
  del prompt (Soporte técnico → registro MX → explica y pide lo mínimo).
  Estado `AI_ACTIVE`, 0 leads: **correcto**, en esa conversación no se pidió
  canalizar (la prueba de «cancelar hosting» no llegó a hacerse; las dos
  pruebas en la misma ventana comparten conversación).

**No comprobado:** creación de un lead en producción;
`options.enable_lead_notifications` en producción (el comando que se usó
preguntaba por la clave sin `options.` y devolvió `NULL`; `cim` dijo «sin
cambios», así que debería ser `true`).

Comandos para revisar (no muestran secretos; los dígitos se ocultan):

```bash
drush php:eval 'foreach (["ai_whatsapp_conversation"=>"Conversaciones","ai_whatsapp_message"=>"Mensajes","ai_whatsapp_lead"=>"Leads"] as $t=>$l) { echo $l.": ".\Drupal::entityQuery($t)->accessCheck(FALSE)->count()->execute()."\n"; }'
drush php:eval 'var_export(\Drupal::config("ai_whatsapp_automation.settings")->get("options.enable_lead_notifications")); echo "\n";'
drush watchdog:show --count=10 --severity-min=3
```

---

## 2026-09-30 — Producción: no se podía entrar como admin

**Causa (comprobada en el servidor):** `admin` activo y con rol de
administrador, 2 intentos fallidos (no bloqueado: el límite es 5). La primera
entrada fue con `drush uli` y nunca se le puso contraseña en producción.

**Qué se hizo:** se limpiaron los intentos fallidos (`flood`), el equipo entró
con `drush uli` y cambió la contraseña. **Comprobado:** el equipo ya entra con
usuario y contraseña. Lección anotada en [despliegue.md](despliegue.md).

---

## 2026-09-30 — Claves de proveedores que sobreviven a los despliegues

**Problema:** las claves generales del módulo (OpenAI, token de Twilio,
WhatsApp Cloud, Evolution) se guardaban en la configuración
`ai_whatsapp_automation.settings`. En `config/sync` están vacías a propósito
(no deben ir a git), así que cada `drush cim` de un despliegue **las borraba**
de producción.

**Qué se hizo** (sin cambiar la pantalla: mismos campos y textos)

- `SecretStore`: el formulario guarda esas 6 claves en el State de Drupal
  (base de datos, nunca exportado, `cim` no lo toca). En la configuración
  quedan vacías.
- `SecretsConfigOverride`: pone esas claves encima de
  `ai_whatsapp_automation.settings` al leerla, así que el resto del módulo
  (OpenAI, embeddings, Twilio…) no cambió. Una clave en
  `settings.local.php` sigue ganando.
- `update_11041`: en un sitio que ya tenía claves en la configuración, las
  mueve al State.
- Las credenciales por cuenta de WhatsApp (Twilio SID/token, instancia de
  Evolution) no se tocaron: ya eran por cuenta y no viajan en los
  despliegues. OpenAI por cliente queda para el futuro.

**Comprobado**

- Pruebas nuevas (kernel y funcional): el formulario guarda en el State, la
  configuración exportada queda vacía, guardar con el campo vacío conserva la
  clave, `settings.local.php` gana, la actualización mueve las claves
  existentes, claves desconocidas se rechazan.
- Sabotaje: sin registrar el override fallan 3 pruebas.
- Real en local: clave en el State + `drush cim` con un cambio en
  `ai_whatsapp_automation.settings` → el cambio se importó y la clave
  siguió. `config/sync` restaurado y la clave de prueba borrada.
- Suites completas (módulo, `goblin_portal`, tema): 166 pruebas OK.
  `phpcs` (Drupal, DrupalPractice) sin observaciones en los archivos nuevos.

**Desplegado en producción** (`4942bfc`): `updb` corrió la 11041 («No
provider secrets were stored in config»), `cim` sin cambios, `git status`
limpio y el State sin claves todavía.

**No comprobado:** que una clave escrita en producción sobreviva al siguiente
despliegue (se verá en el próximo).

---

## 2026-09-30 — Chat web: «ya no me deja interactuar»

**Causa (comprobada):** 8 mensajes del visitante entre 12:12 y 12:17; el
límite era 8 por conversación cada 15 minutos. El servidor respondía 429 con
el motivo, pero el chat lo descartaba y mostraba «No pude responder en este
momento», que parece una falla.

**Qué se hizo**

- El chat muestra el motivo que manda el servidor (límites); el mensaje
  genérico queda solo para fallos sin explicación. Comprobado interceptando
  la llamada (429 → mensaje del límite; 500 → genérico).
- Límite subido a 20 mensajes / 15 min (decisión del equipo), en el script.

**Por decidir:** en el chat web la IA sigue respondiendo después de crear un
lead (en WhatsApp se detiene porque un operador contesta por ahí). En el chat
web no hay canal para que una persona responda.

---

## 2026-09-30 — Bot Goblin en vivo, chat web y vista previa de colores

**Qué se hizo**

- Clave de OpenAI de pruebas en `settings.local.php` como sobrescritura de
  config (no en la base de datos ni en git; comprobado).
- Prueba en vivo del bot con OpenAI (`tests/bots/goblin-live.php`), mismo
  camino que un mensaje de WhatsApp salvo el envío. Tres rondas.
- **Corregido en el módulo:** el lead tomaba nombre y correo de toda la
  conversación, incluidos los ejemplos del bot: un lead quedó con el correo
  `juan@tunegocio.com`, que era un ejemplo. Ahora usa lo que escribió el
  cliente más el resumen final. Prueba nueva (rompe si se revierte).
- **Reglas del bot** (script, no el prompt): confirmar el nombre solo al
  canalizar y no usar el del perfil; no pedir número por WhatsApp (sí en el
  chat web); nada de preguntas tras «Datos capturados»; contraseñas
  compartidas: no repetirlas y pedir cambiarlas; no ofrecer precios ni
  inventar URLs.
- **Chat web:** estaba apagado en el bot (403 «Chat not available»); el script
  lo enciende con identidad Goblin. La **bienvenida salía en un solo
  renglón**: el servidor la imprimía sin el formateador de las respuestas;
  ahora usa el mismo. En **WhatsApp no pasaba**: comprobado que llegan los 9
  saltos de línea al cuerpo que se envía a Evolution.
- **Vista previa de color** en el formulario del bot: selector nativo
  sincronizado con el código, contraste del texto blanco en vivo para el
  color principal, y validación de formato en el servidor. Mismo acomodo en
  ambos campos (corregido tras verlo el equipo).
- Leads activados (`enable_lead_notifications: true`, en config/sync).

**Comprobado**

- Ronda final: cancelación → lead «Juan Pérez» con el número de WhatsApp;
  cliente molesto → no pide el nombre de entrada; contraseña → pide no
  compartirla y cambiarla, nunca la repite; precio → no inventa, canaliza;
  chat web → pide nombre y celular, lead «Pedro Luna» correcto.
- Chat web real en el navegador: respuesta de OpenAI formateada (18 bloques),
  sin errores de consola.
- 160 pruebas en verde (módulo 122, portal 8, tema 30).

**No comprobado**

- Envío real por WhatsApp (no hay número conectado todavía).
- Avisos de lead por WhatsApp (faltan los números del equipo).

---

## 2026-09-30 — Bot «Goblin»

**Qué se hizo**

- Prompt maestro del equipo guardado en `docs/bots/goblin/prompt.md` (texto
  sin cambios; solo se normalizaron viñetas a Markdown).
- `scripts/bots/goblin.php`: crea o actualiza el bot desde ese archivo (los
  bots son contenido y no viajan con config/sync). Ejecutado: bot id 3,
  cliente Goblin; una segunda ejecución actualiza sin duplicar.
- Leads del bot configurados para Goblin: las señales por defecto del módulo
  eran de otro negocio (origen, destino, transporte…) y nunca se habrían
  cumplido. Señales: nombre, contacto, categoría, descripción (mínimo 3) y
  una regla que hace al bot cerrar cada escalamiento con «Datos capturados:».

**Comprobado**

- Prompt guardado en el bot idéntico al archivo.
- 5 conversaciones simuladas contra `LeadHandoffService` (sobre copia de la
  base, restaurada después): lead solo cuando el bot resume con los datos;
  nunca mientras los pide ni en preguntas informativas. Con las señales por
  defecto, el mismo caso no generaba lead.

**Encontrado**

- Los leads están **desactivados** globalmente: sin activarlos ningún bot
  crea leads.
- Al crearse un lead la conversación pasa a atención humana y la IA deja de
  responder en ella (comportamiento del módulo).
- El teléfono del lead es el número de WhatsApp de la conversación, no el que
  el cliente escribe en el chat si fuera otro.

**No comprobado**

- Respuestas reales de OpenAI (sin clave en local).

---

## 2026-09-30 — Tabla de Enrutamiento que cabe

**Qué se hizo**

- Causa medida: Claro limita el contenido a **1080 px** (920 px a 1280 con la
  barra lateral del admin); la tabla tenía 10 columnas y un ancho mínimo de
  1080 px, así que «Editar cuenta» quedaba fuera.
- De 10 a 7 columnas sin perder datos: el número va bajo la cuenta; el
  proveedor y la conexión bajo el estado («Evolution · Desconectada»).
- Nueva acción **«Editar bot»** junto a «Editar cuenta» (el bot define cómo
  responde el número). Ambas solo si hay permiso.
- La tabla usa el componente compartido del módulo (`ResponsiveTable`): se
  desplaza dentro de su caja con sombra de aviso, como las demás listas, y
  los botones de acción de siempre (`aiwa-actions`).
- Reglas CSS por posición de columna (`nth-child`) cambiadas por clases: al
  reordenar columnas habrían apuntado a otras.

**Comprobado**

- Medido ancho de caja vs. tabla: cabe entera para el admin a 1280 y 1440 px
  y para el gestor a 1024, 1280 y 1440. Solo el admin a 1024 (con su barra
  lateral) se desplaza dentro de la caja. Capturas revisadas.
- 159 pruebas en verde (nuevas: 7 columnas y enlace «Editar bot» del gestor).
  Recorrido por roles y QA en los 3 motores: 0 problemas.

---

## 2026-09-30 — Fuera los overrides de la cuenta de WhatsApp

**Decisión del equipo:** «Prompt override» confundía (¿manda el del bot o el
de la cuenta?). Manda el bot. Se quitaron los tres valores propios de la
cuenta: prompt, modelo y base de conocimiento. Si un número necesita otro
comportamiento, se le asigna otro bot.

**Qué se hizo**

- Campos eliminados de la cuenta; `BotManagerService` lee solo del bot; la
  tabla de Enrutamiento ya no muestra «Instrucciones propias».
- `update_11040` + `AccountOverrideMigration`: si un sitio tiene cuentas con
  overrides, cada una recibe **una copia de su bot** con esos valores
  («Bot — Cuenta») y sus conversaciones abiertas se mueven a ella, así que
  todo responde igual que antes. La copia nace con el chat web apagado, token
  nuevo y sin clave. Sin tocar fechas de actividad. Luego se borran las
  columnas.
- Actualizaciones antiguas (11006, 11007, 11032) blindadas para sitios que
  nunca tuvieron esas columnas.
- Asistente de Enrutamiento: ya no ofrece «Agregar cliente» a quien no puede
  crear clientes (el Gestor veía un botón que acababa en «acceso denegado»);
  muestra «Lo hace un administrador.».

**Comprobado**

- Prueba real en la base local con overrides puestos y copia de seguridad
  (`ddev snapshot antes-de-quitar-overrides`): antes/después, prompt, modelo
  y base **idénticos** en ambas conversaciones; el número sin overrides
  intacto; columnas borradas, sin definiciones pendientes. Restaurada la copia
  y repetido: mismo resultado; tercera ejecución sin cambios.
- Descubierto al revisar el código: el bot se toma primero de la
  conversación. Sin mover las conversaciones, las abiertas habrían seguido sin
  el override. La migración las mueve y hay prueba de ello.
- 159 pruebas en verde (módulo 121, portal 8, tema 30). Sabotaje: sin mover
  conversaciones, sin reiniciar el chat web del bot copia, sin el blindaje de
  11032: las pruebas se ponen en rojo.
- Recorrido por roles y QA visual en Chromium, WebKit y Firefox: 0
  problemas. Formulario de cuenta sin los campos y guardando bien.

**No comprobado**

- Una conversación real respondida por OpenAI (no hay clave en local): se
  comprobó lo que se le enviaría (prompt, modelo, base), no la respuesta.

---

## 2026-09-30 — Roles: Atención a clientes y Gestor

**Qué se hizo**

- Definición del equipo: el cliente ve y opera solo lo de su empresa
  (panel, conversaciones, mensajes, prospectos). El **Gestor** es del equipo
  interno y configura bots, bases de conocimiento, WhatsApp/QR, enrutamiento
  y lee la bitácora, para todos los clientes. Tabla completa:
  [roles-y-permisos.md](roles-y-permisos.md).
- Módulo `ai_whatsapp_automation`:
  - Un permiso por sección (`SectionAccess`); «administer … entities» sigue
    abriendo todo (compatibilidad).
  - Roles en `PanelRoles`, creados en `hook_install` (antes solo en un update,
    así que las instalaciones nuevas no tenían rol de cliente) y en
    `update_11039` para sitios existentes. Ejecutado aquí: creó ambos.
  - Menú propio del panel (`PanelNavigation`) con cerrar sesión, para quien no
    tiene la barra de Drupal. Resuelve el pendiente crítico de ayer.
  - Corregido: el control de acceso ignoraba el permiso propio de las bases
    de conocimiento; el filtro de listas habría dejado vacía la lista de bots
    de cualquier no-administrador; «ver el nombre» de un registro se
    convertía en «ver» (core), así que el gestor no veía el cliente de un bot.
- `goblin_portal`: cada persona aterriza en su primera sección (Gestor → Bots).

**Comprobado**

- 118 pruebas del módulo (111 previas + 7 nuevas de roles) y 38 de portal y
  tema, en verde. Linter limpio en lo tocado.
- Sabotaje: sin la excepción del filtro, sin `viewLabelOperation`: las
  pruebas se ponen en rojo. Una prueba que al principio NO detectaba su
  sabotaje (el listado imprime el cliente sin control de acceso) se cambió a
  la ficha del bot, donde sí depende del arreglo.
- Recorrido real con `demo-cliente` (entrando con su correo) y `demo-gestor`
  en Chromium, WebKit y Firefox: aterrizaje, menú exacto, cada sección, un
  detalle, 403 en lo ajeno, móvil y cerrar sesión: 0 problemas. QA general:
  28 pantallas × 3 motores, 0 problemas.
- Capturas revisadas. Encontrado mirándolas (no lo detectó ninguna prueba):
  con 7 secciones la cuenta del gestor caía sola a una segunda fila. El menú
  pasó a dos filas fijas (marca y cuenta; debajo, pestañas).

**No comprobado**

- Safari real (lo revisa el equipo).
- Envío real de mensajes desde «Operar conversaciones» (no hay proveedor
  de WhatsApp configurado en local).

---

## 2026-09-29 — QA en varios navegadores y logos

**Qué se hizo**

- `tests/visual` como paquete npm con versiones fijas: Playwright (motores
  Chromium, WebKit, Firefox) y selenium-webdriver (Safari y Firefox reales).
  `geckodriver` instalado con Homebrew. Instrucciones: [tests/visual/README.md](../tests/visual/README.md).
- Logos SVG en el tema (`images/brand/`): color, blanco y la «G» sola
  (recortada del SVG original, sin redibujar).
- La «G» es el logo de la barra lateral de administración en lugar de la gota
  de Drupal (`navigation.settings`, en `config/sync`).
- Texto de ayuda del logo corregido: ya no dice «48 px máximo» (en acceso se
  ve más grande).
- Permisos de archivos del tema normalizados (el generador los dejó en
  700/600; git no los guarda, pero en local impedían servirlos bien).

**Comprobado**

- QA visual: 28 pantallas × 3 motores (Chromium 153, WebKit 26.6, Firefox
  155): 0 problemas. Capturas revisadas: idénticas.
- Firefox real 156: redirige a `/user/login`, Poppins cargada, imagen en WebP,
  botón 20 px/700 con 3.16:1, logo cargado, sin scroll horizontal.
- Logo de la barra lateral: carga a 40×40 (captura revisada).
- 37 pruebas de tema y portal en verde tras los cambios.

**No comprobado**

- Safari real: falta activar la automatización (requiere tu contraseña).

---

## 2026-09-29 — Acceso: portada → login → panel

**Qué se hizo**

- Módulo nuevo `goblin_portal` ([README](../web/modules/custom/goblin_portal/README.md)):
  `/` redirige (`/inicio`) al acceso o al panel; tras iniciar sesión se va al
  panel (o a la cuenta si no hay permiso); acceso con usuario o correo
  (decorador de `user.auth`, control de fuerza bruta de core intacto).
- Pantalla de acceso según el diseño aprobado: dos columnas, logo a color,
  eslogan «Bot automatizado IA», imagen de fondo, botón naranja «Entrar».
  También en recuperar contraseña. Se añadió «¿Olvidaste tu contraseña?», que
  el diseño no tenía; «Contaseña» del diseño corregido a «Contraseña».
- Marca como predeterminada del tema: logo, favicon, paleta y Poppins
  alojada en el tema.
- Sitio en español (módulos language y locale, traducciones de core), sin
  prefijo `/es` en las URLs.
- `ai_whatsapp_automation.info.yml`: declarada la dependencia `drupal:options`
  que faltaba (usa campos `list_string`). Sin ella el módulo falla en una
  instalación sin `options`.

**Comprobado**

- 148 pruebas (módulo 111, tema 30, portal 7), 1301 aserciones, en verde.
- Sabotaje: sin la búsqueda por correo y sin la redirección, las pruebas del
  portal se ponen en rojo.
- QA visual: 28 pantallas, anónimo y admin, 4 anchos: 0 problemas.
  Encontrado y corregido midiendo: botón «Entrar» a 16 px (blanco sobre
  naranja no cumplía); placeholder a 4.3:1; palabras partidas en español en
  Claro y en la barra de Navigation (causado por el cambio de idioma).
- Botón «Entrar»: blanco sobre naranja 3.16:1 a 20 px/700 = texto grande
  (mínimo 3:1). Imagen de fondo: 62 KB WebP; en móvil no se descarga.
- Recorrido con un rol cliente **temporal** (los permisos del módulo para
  clientes): portada → acceso con correo → panel. Rol y usuario borrados;
  no quedó nada en la configuración.

**No comprobado / encontrado sin resolver**

- El cliente llega al panel **sin menú y sin cerrar sesión** (pendiente 1).
- Safari y Firefox reales (pendiente 4); solo Chrome automatizado.

---

## 2026-09-29 — Tema Goblin

**Qué se hizo**

- Tema `goblin` en `web/themes/custom/goblin`, generado con el Starterkit de
  Drupal 11.4 y activado como tema por defecto. Claro sigue para `/admin`.
- Colores configurables por **papel** (principal, encabezado y pie, fondo,
  superficie, texto) en `/admin/appearance/settings/goblin`. El resto de tonos
  se calculan para cumplir WCAG; el formulario rechaza texto ilegible.
- Logo y favicon: se reutiliza la subida de core (no se reimplementa); el
  logo acepta además WEBP. Formatos: PNG, GIF, JPG, JPEG, APNG, WEBP, SVG.
- Logo y favicon provisionales propios en lugar de la gota de Drupal.
- Bloques reordenados (título y pestañas sobre el contenido); se quitó
  «Powered by Drupal».
- Todos los colores literales del Starterkit pasados a tokens.
- El botón de «Log in» / «Reset password» ahora es principal (core lo deja
  como botón secundario).
- QA visual reutilizable: `tests/visual/goblin-qa.mjs`.
- Detalle técnico y decisiones: [README del tema](../web/themes/custom/goblin/README.md).

**Comprobado**

- 27 pruebas (19 unitarias + 8 funcionales), 229 aserciones, en verde.
- Pruebas rotas a propósito: sin la validación de contraste y sin WEBP, las
  pruebas correspondientes se ponen en rojo.
- QA visual: 28 pantallas (anónimo y admin; 1440/1024/820/390 px; incluye
  login con error y 404): 0 problemas. Contrastes medidos sobre el color
  efectivo: texto 15.73:1, botón 7.9:1, enlaces 7.33:1, pie 8.44:1, error
  5.75:1. Capturas de login (escritorio) y login con error (móvil) revisadas.
- Encontrado y corregido gracias a la medición: menú activo negro sobre el
  encabezado oscuro (1.31:1), selector del menú de cuenta que no existía,
  botón de login sin estilo principal.

**Deducido / no comprobado**

- La dependencia de caché del tema no es la que refresca las páginas: core
  invalida `rendered` al guardar la configuración del tema. Romperla no pone
  la prueba en rojo; la prueba protege el comportamiento, no esa línea.
- Roles distintos de admin: no hay aún usuarios de otros roles.
- No se probó en navegadores reales (Safari, Firefox) ni en un móvil físico.

---

## 2026-09-29 — Instalación inicial

**Qué se hizo**

- Proyecto `drupal/recommended-project` 11.x (core 11.4.8) sobre DDEV:
  PHP 8.4.18, MariaDB 11.8, Drush 13.
- Se eligió DDEV en lugar de un `docker-compose` propio: es Docker igualmente,
  es el estándar de la comunidad Drupal, y ya estaba instalado en la máquina.
- El módulo `ai_whatsapp_automation` se movió de `modulo custom/` a
  `web/modules/custom/` y se activó.
- Dependencias que el módulo usa pero no declaraba:
  - `aws/aws-sdk-php` (Composer): lo usa el plugin de correo Amazon SES.
  - `poppler-utils` (sistema): da `pdftotext` para leer PDF en el RAG.
  - Archivos privados: el módulo marca ERROR si los documentos quedan públicos.
- `settings.php`: archivos privados en `../private`, configuración en
  `../config/sync`, e inclusión de `settings.local.php` para los secretos.
- Configuración exportada a `config/sync` (139 archivos).
- `drupal/core-dev` como dependencia de desarrollo, para las pruebas.
- Usuario super admin `admin` con el rol `administrator`.
- Repositorio inicializado y subido a `git@github.com:ads-josera/ia-goblin.git`.

**Comprobado**

- Pruebas del módulo: 111 pasan, 956 aserciones. Una deprecación (pendiente 4).
- Las 22 pantallas del módulo responden 200 como admin, sin errores; el
  panel responde 403 a un visitante anónimo.
- Informe de estado: requisitos del módulo (`pdftotext`, archivos privados) en
  OK. Registro de errores limpio.
- La contraseña del admin autentica.
- Antes del commit se revisó que no hubiera secretos: las claves del módulo
  en `config/sync` están vacías.

**No comprobado**

- Roles distintos de admin (pendiente 2).
- `site:install --existing-config` (pendiente 3).
