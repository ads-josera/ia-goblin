# Bitácora del proyecto

Registro de lo que se ha hecho, por qué, y lo que queda pendiente. La entrada
más reciente va arriba. Cada entrada separa lo **comprobado** (se ejecutó) de
lo **no comprobado**.

---

## Dónde quedamos

- Entorno local funcionando en https://ia-goblin.ddev.site con el módulo
  activado y el tema **Goblin** como tema del frontend.
- Repositorio en `main` en GitHub, al día.
- **Siguiente paso:** aplicar la marca real (Goblin Creative, archivos en
  `docs/`) desde los ajustes del tema — pendiente de decidir cómo (ver
  pendiente 1).

## Pendientes

| # | Pendiente | Prioridad |
|---|-----------|-----------|
| 1 | Aplicar la marca Goblin Creative: qué logo (color o blanco) y qué colores por papel. Decidir si los archivos fuente de `docs/` (.ai, .svg, .png) se versionan | Alta |
| 2 | Mover las API keys del módulo a `settings.local.php` antes de guardarlas en la interfaz (si no, `drush cex` las sube a git) | Alta |
| 3 | Recorrer el módulo con las cuentas de los roles cliente y operador, no solo como admin | Alta |
| 4 | Comprobar `drush site:install --existing-config` en una copia limpia | Media |
| 5 | Añadir `#[LegacyRequirementsHook]` a `ai_whatsapp_automation_requirements()` (deprecado en Drupal 11.3, se elimina en 13) | Baja |
| 6 | Tema Goblin: campo de texto hexadecimal junto a cada selector de color (hoy solo hay selector nativo) | Baja |
| 7 | Documentar el despliegue en [despliegue.md](despliegue.md) cuando se elija servidor | Cuando toque |

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
