# Bot «Goblin» — cliente Goblin

| Archivo | Qué es |
|---------|--------|
| [prompt.md](prompt.md) | El prompt maestro. **La fuente de verdad**: se edita aquí |
| [../../../scripts/bots/goblin.php](../../../scripts/bots/goblin.php) | Crea o actualiza el bot con ese prompt y su configuración de leads |

## Cargar o actualizar el bot

```bash
ddev drush php:script scripts/bots/goblin.php     # local
drush php:script scripts/bots/goblin.php          # servidor
```

Los bots son **contenido**, no configuración: no viajan con `config/sync`.
Este script es cómo el bot llega a cada entorno. Crea el bot si no existe y,
si existe, actualiza solo el prompt y los leads; lo demás (límites, chat web,
notificaciones) se respeta si se cambió desde la interfaz.

Si se edita el prompt desde la interfaz, hay que copiarlo también a
`prompt.md`, o la siguiente ejecución del script lo sobrescribe.

## Cómo se crea un lead

El bot no crea leads: los crea el módulo (`LeadHandoffService`) cuando en la
conversación hay **un contacto**, **al menos 3 de estas señales** y **una
frase de cierre** del bot.

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
responder en ella) y se avisa por WhatsApp a los números de notificación.

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

`https://<sitio>/ai-whatsapp-automation/chat/<token>` (token en el bot,
sección avanzada del chat web). El script lo deja encendido, con el nombre
«Goblin», la bienvenida del prompt, español y color `#065885` (las burbujas
del visitante llevan texto blanco: el naranja no llega a 4.5:1). Límites:
**20 mensajes por conversación cada 15 minutos** (decisión del equipo: un caso
de soporte normal necesitó 9 y el valor por defecto, 8, lo cortaba), 50
conversaciones y 1.50 USD al día. Al llegar a un límite el chat muestra el
motivo («Intenta nuevamente en unos minutos»).

Para volver a probar desde cero: abrir el chat en una ventana privada (la
sesión se guarda en el navegador).

## Prueba en vivo con OpenAI

`tests/bots/goblin-live.php` + `goblin-live.json` (12 conversaciones). Gasta
tokens y crea registros: correr sobre una copia (`ddev snapshot`) y
restaurar. Instrucciones en la cabecera del script.

## Requisitos para que funcione en vivo

- Clave de OpenAI configurada (en `settings.local.php`, nunca en la interfaz;
  ver README del proyecto).
- Leads activados (hecho, en `config/sync`) y **números de WhatsApp del
  equipo** para recibir los avisos (pendiente).
- Una cuenta de WhatsApp del cliente Goblin con este bot asignado
  (Enrutamiento).

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
