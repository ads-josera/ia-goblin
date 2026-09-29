# Goblin

Tema del frontend de IA Goblin con la marca **Goblin Creative**: inicio de
sesión, recuperación de contraseña y cualquier página pública. Las pantallas
de `/admin` (incluidas las del módulo `ai_whatsapp_automation`) siguen en
Claro.

Generado con el Starterkit de Drupal 11.4 (`vendor/bin/dr generate-theme`);
a partir de ahí es código propio.

## Qué se personaliza y dónde

Apariencia → Goblin → *Settings* (`/admin/appearance/settings/goblin`):

| Ajuste | Formatos / valores | Lo implementa |
|--------|--------------------|---------------|
| Colores de la marca | 5 colores por papel | Este tema |
| Logo | PNG, GIF, JPG, JPEG, APNG, WEBP, SVG | Core; el tema añade WEBP |
| Favicon | ICO, PNG, GIF, JPG, APNG, SVG, WEBP | Core |

### Colores: se eligen papeles, no tonos

| Papel | Dónde se ve |
|-------|-------------|
| Color principal | Botones, enlaces, indicador de foco |
| Encabezado y pie | Franja superior y pie de página |
| Fondo de la página | Detrás de todo |
| Superficie | Tarjetas, formularios, campos |
| Texto | Texto principal |

Todo lo demás se **calcula** en `src/Color/ColorScheme.php` para que ninguna
combinación deje algo ilegible:

- Texto sobre botones y encabezado: blanco o negro puro, el que contraste más
  (garantiza ≥ 4.58:1 con cualquier color).
- Texto atenuado, bordes de campos y texto del pie: la mezcla más suave que
  cumple WCAG (4.5:1 texto, 3:1 bordes).
- Enlaces: usan el color principal solo si llega a 4.5:1; si no, el color de
  texto (siempre van subrayados). El formulario avisa cuando pasa.
- El formulario **rechaza** un texto que no llegue a 4.5:1 sobre el fondo o la
  superficie.

## Marca predeterminada

- Logo: `logo.svg` (= `docs/logo-color.svg`). Favicon: `favicon.ico`, generado
  de `docs/favicon-goblin.png` (16/32/48 px).
- Paleta por defecto (`ColorScheme::DEFAULTS`): naranja `#e5700c` como
  principal, fondo blanco, superficie `#f7f7f8`, texto `#1d1d1f`. Los azules
  del logo no tienen papel propio todavía.
- Tipografía: **Poppins** 400/500/600/700, alojada en `fonts/poppins`
  (licencia OFL). Ningún visitante pide fuentes a terceros.

## Pantalla de acceso

`templates/layout/page--goblin-auth.html.twig` + `css/layout/auth.css`, para
las rutas de `GoblinHooks::AUTH_ROUTES` (acceso, recuperar contraseña, acceso
de un solo uso). Dos columnas según el diseño aprobado:

- Izquierda: logo del tema, eslogan del sitio (*Configuración básica del
  sitio*), mensajes, formulario y enlace secundario.
- Derecha: `images/auth-background.{webp,jpg}` (1400 px, 62 KB en WebP),
  generada desde `docs/diseno/fondo-login-original.jpg`. Diseño de
  referencia: `docs/diseno/login-diseno-aprobado.jpg`.
  Por debajo de 60rem la columna desaparece y la imagen **no se descarga**
  (el `background-image` vive dentro de la media query).
- El título de la página queda solo para lectores de pantalla; las pestañas
  *Iniciar sesión / Restablecer* se sustituyen por el enlace secundario.

### Botón blanco sobre naranja

Blanco sobre `#e5700c` da 3.16:1: **no vale** para texto normal (4.5:1), sí
para texto grande (≥ 18.66 px en negrita, umbral 3:1). Por eso:

- `--goblin-color-on-accent` (texto normal) sale negro con este naranja.
- `--goblin-color-on-accent-large` sale blanco y **solo** se usa en
  `.button.button--large` (20 px, peso 700). Mantener tamaño, peso y token
  juntos. La clase va doble porque `input[type="submit"] { font: inherit }`
  la pisaba y dejaba el texto en 16 px (encontrado midiendo).
- Los formularios de cuenta reciben `button--primary button--large`
  (`GoblinHooks::formAlter`).

## Arquitectura

```
src/Color/ColorMath.php        Aritmética de color y contraste WCAG (pura)
src/Color/ColorScheme.php      Papeles editables → tokens CSS derivados
src/Form/ThemeSettingsFormAlter.php  Campos de color, validación, formatos del logo
src/Hook/GoblinHooks.php       Hooks (OOP): tokens en <head>, botón principal
                               en formularios de cuenta, plantilla de acceso,
                               variables de logo/eslogan, pie de página
css/base/tokens.css            Todos los tokens (colores de respaldo, tipo, espacio)
config/schema/goblin.schema.yml  Esquema de goblin.settings (colors.*)
```

- Los colores llegan a la página como `<style data-goblin-colors>:root{…}` en
  `<head>`. `tokens.css` lleva los mismos valores por defecto solo como
  respaldo; `ColorSchemeTest` falla si se desincronizan.
- Ningún CSS fuera de `tokens.css` usa un color literal. Para añadir uno,
  créalo como token con nombre por papel (`--goblin-color-peligro`, no
  `--rojo`).
- Los colores semánticos (correcto, aviso, peligro) no dependen de la marca.
- Los valores guardados se vuelven a validar al leerlos: un valor corrupto
  (por ejemplo, puesto con drush) cae al predeterminado y nunca llega al CSS.

## Decisiones no obvias

- **La validación va en `#element_validate`, no en `$form['#validate']`.** Un
  alter de tema se ejecuta antes de `FormBuilder::prepareForm()`; si añade
  `#validate`, core ya no registra su `::validateForm`, que es el que procesa
  la subida del logo y el favicon.
- **Caché:** al guardar cualquier `TEMA.settings`, core invalida el tag
  `rendered` (`ConfigCacheTag`), así que los visitantes anónimos ven los
  colores nuevos sin vaciar caché. El tema declara además su dependencia de
  `goblin.settings`.
- **SVG:** solo quien tiene `administer themes` puede subirlo, y se muestra en
  `<img>`, donde un script embebido no se ejecuta. Si algún día se da ese
  permiso a usuarios no confiables, hay que sanear los SVG.
- `favicon.ico` se genera con ImageMagick desde `docs/favicon-goblin.png`:
  `convert favicon-goblin.png -define icon:auto-resize=48,32,16 favicon.ico`.

## Pruebas

```bash
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=http://localhost \
  vendor/bin/phpunit -c web/core web/themes/custom/goblin/tests'
```

QA visual (contraste medido, desbordes, scroll horizontal, a 1440/1024/820/390 px):
ver `tests/visual/goblin-qa.mjs` en la raíz del proyecto.
