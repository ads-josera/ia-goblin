# Bitácora del proyecto

Registro de lo que se ha hecho, por qué, y lo que queda pendiente. La entrada
más reciente va arriba. Cada entrada separa lo **comprobado** (se ejecutó) de
lo **no comprobado**.

---

## Dónde quedamos

- `/` lleva al inicio de sesión (diseño aprobado Goblin Creative); tras entrar
  se llega al panel del módulo. Se entra con usuario o correo. Sitio en
  español.
- Repositorio en `main` en GitHub, al día.
- **Siguiente paso:** decidir la navegación de los clientes (pendiente 1):
  hoy entran al panel pero no tienen menú ni forma de cerrar sesión.

## Pendientes

| # | Pendiente | Prioridad |
|---|-----------|-----------|
| 1 | **Clientes sin navegación ni salida.** `update_11036` les quitó la barra de Drupal contando con un menú propio del panel que no está en este código. Un cliente entra al panel y no puede ir a otra sección ni cerrar sesión. Decidir: construir el menú del panel o darles la barra lateral de Navigation | **Crítica** |
| 2 | **El rol de cliente no se crea en instalaciones nuevas.** Solo lo crea `update_11034`, que no corre al instalar. En este sitio no existe. Añadir la creación en `hook_install` y un update para sitios ya instalados | **Alta** |
| 3 | Mover las API keys del módulo a `settings.local.php` antes de guardarlas en la interfaz (si no, `drush cex` las sube a git) | Alta |
| 4 | Revisión en Safari y Firefox reales (ver opciones en la entrada de hoy) | Media |
| 5 | Decidir si los `.ai` de `docs/` se versionan (hoy fuera de git) | Baja |
| 6 | Comprobar `drush site:install --existing-config` en una copia limpia | Media |
| 7 | Añadir `#[LegacyRequirementsHook]` a `ai_whatsapp_automation_requirements()` (deprecado en 11.3, se elimina en 13) | Baja |
| 8 | Tema Goblin: campo hexadecimal junto a cada selector de color | Baja |
| 9 | Un usuario con sesión que abre `/user/login` recibe «acceso denegado» (comportamiento de core); podría redirigirse a su inicio | Baja |
| 10 | Documentar el despliegue en [despliegue.md](despliegue.md) cuando se elija servidor | Cuando toque |

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
