# QA visual

Mide lo que una captura solo sugiere: contraste real (color efectivo, no el
declarado), scroll horizontal, elementos que se salen, palabras partidas y
fondos transparentes. En 4 anchos (1440, 1024, 820, 390 px), como visitante y
como admin, incluido el login con error.

## Instalación (una vez)

```bash
cd tests/visual
npm install
npx playwright install chromium webkit firefox
brew install geckodriver          # solo para Firefox real
```

Safari real, una vez: Safari → Ajustes → Avanzado → «Mostrar funciones para
desarrolladores web»; luego **Desarrollo → Permitir automatización remota**, y
en la terminal `sudo safaridriver --enable` (pide la contraseña de
administrador del Mac).

## Uso

```bash
# Motores Chromium, WebKit (motor de Safari) y Firefox, en segundo plano.
# Un enlace de acceso por motor: cada enlace sirve una sola vez.
U() { ddev drush uli --uri=http://ia-goblin.ddev.site --no-browser; }
GOBLIN_ULI_CHROMIUM=$(U) GOBLIN_ULI_WEBKIT=$(U) GOBLIN_ULI_FIREFOX=$(U) npm run qa

# Solo uno:
node run.mjs webkit

# Safari y Firefox reales (la pantalla de acceso):
npm run qa:real

# Recorrido con la cuenta de cada rol (cliente y gestor): entrar, abrir
# cada sección de su menú y un detalle, comprobar los 403, móvil y salir.
ROLE_CLIENT_ID=... ROLE_CLIENT_PASS=... ROLE_MANAGER_ID=... ROLE_MANAGER_PASS=... \
  node roles-walk.mjs [chromium|webkit|firefox]
```

Las capturas quedan en `screenshots/` (fuera de git). Sale con código 1 si
hay algún problema.

## Archivos

| Archivo | Qué hace |
|---------|----------|
| `goblin-qa.mjs` | Las comprobaciones. También lo ejecuta la skill browser-automation |
| `run.mjs` | Lo corre en Chromium, WebKit y Firefox (Playwright) |
| `run-real.mjs` | Pantalla de acceso en Safari y Firefox reales (WebDriver) |
| `roles-walk.mjs` | Recorrido completo con la cuenta de cada rol |

WebKit es el motor de Safari, pero no es Safari: antes de entregar, pasar
también `run-real.mjs`.

## Umbrales

- Texto: 4.5:1. Texto grande (≥ 24 px, o ≥ 18.66 px en negrita): 3:1, y el
  script comprueba que de verdad sea grande antes de aplicarlo.
- Placeholder de los campos: 4.5:1.
