# Bots de Goblin — una recepción y un bot por área

Un solo número de WhatsApp y un solo enlace de chat web. El cliente ve un
menú y elige el área; desde ahí lo atiende el bot de esa área, con su propio
prompt y sus propios encargados.

| Bot | Qué hace |
|-----|----------|
| **Goblin** (recepción) | Responde con el menú **sin llamar a la IA**. Está asignado a la cuenta de WhatsApp y tiene el chat web, así que el enlace no cambió |
| **Goblin Facturación** | Pagos, facturas, renovaciones, cancelaciones, cotizaciones, asesor |
| **Goblin Soporte** | Correo, cPanel / hosting, dominios y DNS |

Cómo se mueve el cliente:

- **«hola» o cualquier cosa** en la recepción → el menú (1️⃣ Facturación y
  atención comercial, 2️⃣ Soporte técnico).
- **El número** (`1`, `2`, `1️⃣`…) → pasa al bot de esa área, que contesta ese
  mismo mensaje.
- **Una palabra clave** de un solo área («necesito mi factura») → pasa directo.
  Si coinciden dos áreas («cancelar mi hosting») se muestra el menú en lugar
  de adivinar.
- **«menú»** (sin importar acentos o mayúsculas, como mensaje completo) → vuelve
  a la recepción. «no encuentro el menú de cPanel» no cuenta: es una pregunta.
- Si en un área le preguntan algo de la otra, el bot le indica escribir
  «menú».

| Archivo | Qué es |
|---------|--------|
| [comun.md](comun.md) | Parte común de los dos bots de área (identidad, datos del lead, escalamiento, seguridad, estilo…) |
| [facturacion.md](facturacion.md) | Lo propio de Facturación, con un encabezado que dice qué atiende |
| [soporte.md](soporte.md) | Lo propio de Soporte, con su encabezado |
| [prompt-maestro-original.md](prompt-maestro-original.md) | El prompt único de antes, **solo como referencia**: ya no se carga en ningún bot |
| [../../../scripts/bots/goblin.php](../../../scripts/bots/goblin.php) | Crea o actualiza los tres bots y el menú |

Prompt de cada área = `comun.md` + el archivo del área. Se separó el prompt
maestro **sin reescribirlo**: mismas secciones, con su número original. Ya no
se usan la 2 (bienvenida) ni la 20 (menú), que ahora muestra la recepción, ni
la 3 (regla del menú) y la 19 (flujo general), porque la clasificación ahora
la hace el menú. Lo único nuevo es el encabezado de cada área.

## Cargar o actualizar los bots

```bash
ddev drush php:script scripts/bots/goblin.php     # local
drush php:script scripts/bots/goblin.php          # servidor
```

Los bots son **contenido**, no configuración: no viajan con `config/sync`.
Este script es cómo llegan a cada entorno. Crea los bots si no existen y, si
existen, actualiza solo prompts, menú y reglas de leads; lo demás (límites,
dominios, **números de aviso**) se respeta si se cambió desde la interfaz.
Se puede correr las veces que haga falta.

Si se edita un prompt desde la interfaz, hay que copiarlo también al archivo
del área, o la siguiente ejecución del script lo sobrescribe.

## Avisos de leads por área

Cada bot de área tiene su campo **«Lead notification WhatsApp numbers»**
(sección *Lead handoff* del bot): ahí van los números del encargado de esa
área. Si está vacío, se usan los de la cuenta de WhatsApp o los generales.
Esos números tampoco reciben respuestas de la IA si escriben al número de
Goblin.

## Configurar un menú desde la interfaz

En el bot de recepción, sección **«Reception menu»**: «Menu areas» (los bots
del menú, en orden: el primero es la opción 1) y «Menu message» (el texto
sobre las opciones). En cada bot de área: «Menu label» (cómo aparece en el
menú) y «Menu keywords». Solo se pueden poner bots del mismo cliente.

## Cómo se crea un lead

Los bots no crean leads: los crea el módulo (`LeadHandoffService`) cuando en
la conversación hay **un contacto**, **al menos 3 de estas señales** y **una
frase de cierre** del bot de área (la recepción nunca crea leads).

| Señal | Palabras |
|-------|----------|
| Nombre | nombre |
| Contacto | teléfono, whatsapp, celular |
| Categoría | categoría |
| Descripción | descripción |

Detectar un nombre por palabras clave no es fiable («Juan Pérez» no contiene
ninguna), así que las reglas de canalización del bot le piden cerrar cada
escalamiento con un bloque **«Datos capturados:»** con esas etiquetas. Mientras
el bot todavía está pidiendo datos, ese bloque no existe y no se crea el lead
antes de tiempo.

Al crearse un lead, la conversación pasa a **atención humana** (la IA deja de
responder en ella) y se avisa por WhatsApp a los números del área.

## Reglas de canalización (además del prompt)

Están en el script y se añaden al prompt; tienen prioridad sobre sus ejemplos.
Salieron de la prueba en vivo:

- **Nombre:** por WhatsApp el número ya se tiene y no se pide; el nombre se
  confirma una vez («¿A nombre de quién registro la solicitud?») y solo al
  decidir canalizar. El nombre del perfil de WhatsApp no se usa. En el chat
  web se piden nombre y celular.
- Después de «Datos capturados» no hay preguntas (la IA deja de responder).
- Contraseñas o códigos que comparta el cliente: no se repiten ni van al
  resumen; se le pide no compartirlas y cambiarlas.
- No ofrecer precios, planes ni información que no tenga; no inventar URLs.

## Chat web

`https://<sitio>/ai-whatsapp-automation/chat/<token>` (token en el bot
«Goblin», sección avanzada del chat web; los bots de área no tienen chat
propio). El script lo deja encendido, con el nombre «Goblin», el menú como
bienvenida, español y color `#065885` (las burbujas
del visitante llevan texto blanco: el naranja no llega a 4.5:1). Límites:
**20 mensajes por conversación cada 15 minutos** (decisión del equipo: un caso
de soporte normal necesitó 9 y el valor por defecto, 8, lo cortaba), 50
conversaciones y 1.50 USD al día, contando también lo que gastan los bots de
área en conversaciones que empezaron en este chat. Al llegar a un límite el chat muestra el
motivo («Intenta nuevamente en unos minutos»).

Para volver a probar desde cero: abrir el chat en una ventana privada (la
sesión se guarda en el navegador).

## Prueba en vivo con OpenAI

`tests/bots/goblin-live.php` + `goblin-live.json` (12 conversaciones). Gasta
tokens y crea registros: correr sobre una copia (`ddev snapshot`) y
restaurar. Instrucciones en la cabecera del script.

## Requisitos para que funcione en vivo

- Clave de OpenAI escrita en la Configuración del módulo (se guarda fuera de
  `config/sync` y sobrevive a los despliegues; ver README del proyecto).
- Leads activados (hecho, en `config/sync`) y **números de WhatsApp del
  equipo** para recibir los avisos (pendiente).
- Una cuenta de WhatsApp del cliente Goblin con el bot **Goblin** (recepción)
  asignado (Enrutamiento).
- Los números de cada encargado en su bot de área (pendiente).

## Prueba en vivo de los bots separados (2026-10-05, local)

Con OpenAI real, sobre una copia de la base de datos (restaurada después):

| Mensaje | Bot | Resultado |
|---------|-----|-----------|
| «hola» | Goblin | Menú, sin IA ✅ |
| «2» | Soporte | Saluda en Soporte y pregunta el tipo de problema ✅ |
| «no me llegan los correos…» | Soporte | Preguntas de primer nivel (sección 5) ✅ |
| «¿y cuánto cuesta renovar mi hosting?» | Soporte | No contesta precios; pide escribir «menú» y elegir 1 ✅ |
| «menú» | Goblin | Menú, sin IA ✅ |
| «1», «quiero cancelar mi hosting» | Facturación | Pide nombre, celular y servicio para canalizar ✅ |
| «necesito mi factura de septiembre» (chat nuevo) | Facturación | Pasa directo por la palabra «factura» ✅ |

Observado: Soporte ofrecía «revisar los registros MX», algo que no puede
hacer. Corregido con una regla de canalización (no ofrecer revisar nada por
su cuenta): sin ella 2 de 2 respuestas lo ofrecían, con ella 0 de 3.

## Memoria de una conversación anterior

Si un visitante del chat (mismo navegador) o un número de WhatsApp vuelve y
su conversación anterior **se cerró sola por inactividad** hace menos de 7
días, la IA recibe un resumen de esa conversación para no hacerle repetir
datos. Por eso, al probar en el mismo navegador, el bot puede mencionar algo
de una prueba anterior. Para probar desde cero: cerrar todas las ventanas
privadas y abrir una nueva.

## Pruebas hechas (2026-09-30)

Contra la lógica real del módulo, sobre una copia de la base de datos:

| Conversación | Resultado |
|--------------|-----------|
| «Quiero cancelar mi hosting», el bot pide nombre y celular | Sin lead ✅ |
| El cliente da nombre y celular, el bot cierra con «Datos capturados» | Lead ✅ |
| «¿Qué es un registro MX?» | Sin lead ✅ |
| Correo que no recibe, el bot pide datos para canalizar | Sin lead ✅ |
| El cliente da sus datos, el bot cierra con el resumen | Lead ✅ |
| Mismo caso con las señales por defecto del módulo | Sin lead (por eso se configuraron) |

No probado: respuestas reales de OpenAI (no había clave en local).
