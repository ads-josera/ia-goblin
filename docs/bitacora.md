# Bitácora del proyecto

Registro de lo que se ha hecho, por qué, y lo que queda pendiente. La entrada
más reciente va arriba. Cada entrada separa lo **comprobado** (se ejecutó) de
lo **no comprobado**.

---

## Dónde quedamos

- **Bot «Goblin»** creado para el cliente Goblin con el prompt maestro del
  equipo ([docs/bots/goblin](bots/goblin/README.md)). Leads configurados y
  probados en simulación. **Falta para usarlo en vivo:** clave de OpenAI,
  activar leads con los números de notificación, y una cuenta de WhatsApp de
  Goblin con este bot.
- Roles listos (Atención a clientes y Gestor): [roles-y-permisos.md](roles-y-permisos.md).
- El bot es la única fuente de prompt, modelo y base de conocimiento.
- En local: datos de demostración («Cliente Demo»), cuentas `demo-cliente` y
  `demo-gestor`.
- Repositorio en `main` en GitHub, al día.

## Pendientes

| # | Pendiente | Prioridad |
|---|-----------|-----------|
| 1 | Textos del módulo aún en inglés en algunas pantallas (p. ej. «Evolution QR connections», etiquetas de la ficha del bot: «System prompt», «Model») | Media |
| 2 | Gráfica «Actividad por día»: con valores pequeños repite la etiqueta del eje (1, 1) | Baja |
| 3 | Mover las API keys del módulo a `settings.local.php` antes de guardarlas en la interfaz (si no, `drush cex` las sube a git). **Necesario para probar el bot Goblin en vivo** | Alta |
| 3b | Activar leads (`enable_lead_notifications`) y definir los números de WhatsApp del equipo que reciben los avisos | Alta |
| 4 | Safari real: lo revisa el equipo. Automatización activada; la sesión se agotaba con Safari abierto. Reintentar con Safari cerrado: `npm run qa:real` | Media |
| 6 | Comprobar `drush site:install --existing-config` en una copia limpia | Media |
| 7 | Añadir `#[LegacyRequirementsHook]` a `ai_whatsapp_automation_requirements()` (deprecado en 11.3, se elimina en 13) | Baja |
| 8 | Tema Goblin: campo hexadecimal junto a cada selector de color | Baja |
| 9 | Un usuario con sesión que abre `/user/login` recibe «acceso denegado» (comportamiento de core); podría redirigirse a su inicio | Baja |
| 10 | Documentar el despliegue en [despliegue.md](despliegue.md) cuando se elija servidor | Cuando toque |

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
