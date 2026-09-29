# Goblin Portal

Entrada al producto IA Goblin. Tres reglas y un ajuste de idioma:

| Qué | Cómo |
|-----|------|
| `/` lleva al acceso | La portada (`system.site:page.front`) es `/inicio`, que redirige: sin sesión → `/user/login`; con sesión → su página de inicio |
| Tras iniciar sesión, al panel | Submit añadido al formulario de acceso. Un `?destination=` explícito sigue ganando |
| Usuario **o correo** para entrar | Decorador de `user.auth` que amplía `lookupAccount()` |
| Sin silabeo automático en español | `css/spanish-admin.css`, adjunto en todas las páginas |

## Página de inicio de cada persona

`StartPage::urlFor()` es el único sitio donde se decide:

- Anónimo → `/user/login`.
- Con permiso `view ai whatsapp automation dashboard` → `/admin/reports/ai-whatsapp-automation`.
- Sin ese permiso → su página de cuenta (nunca a un «acceso denegado»).

## Acceso con correo

`EmailOrUsernameAuthentication` decora `user.auth`:

- Busca primero por nombre de usuario (el comportamiento de core, intacto).
  Solo si no hay coincidencia y el texto es un correo válido, busca por correo.
  Los correos son únicos por cuenta (restricción `UserMailUnique` de core).
- La comprobación de contraseña y el **control de fuerza bruta** siguen siendo
  los de core (`UserLoginForm`); no se tocan.
- El mensaje de error es el mismo con usuario o con correo: no revela si una
  cuenta existe.

## Textos del formulario

«Usuario», «usuario / correo» y «Entrar» se ponen aquí y no en el tema: el
placeholder promete acceso con correo, que es lo que da este módulo.

## Silabeo en español

Claro (`body`) y la barra lateral de Navigation de core aplican
`hyphens: auto`; con `lang="es"` partían palabras («tra-ducción»). La hoja
usa selectores más específicos que los de core porque:

- el CSS de los **temas se carga después** del de los módulos, así que
  `body {}` perdía contra Claro (se usa `html body`);
- la librería de Navigation es interna de core: no se altera, se gana por
  especificidad.

## Instalación y desinstalación

- `hook_install` pone la portada en `/inicio` (salvo durante una
  sincronización de config, donde ya viene en `system.site`).
- `hook_uninstall` la devuelve a `/node` para no dejarla apuntando a una ruta
  que ya no existe.

## Dependencias

`drupal:user` y `ai_whatsapp_automation` (su panel es el destino).

## Pruebas

```bash
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=http://localhost \
  vendor/bin/phpunit -c web/core web/modules/custom/goblin_portal/tests'
```

Cubren: portada anónima y con sesión, acceso con usuario y con correo, contraseña
incorrecta, usuario sin permiso de panel, `?destination=`, y los textos.
