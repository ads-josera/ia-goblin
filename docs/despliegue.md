# Despliegue

> **Estado:** en producción en https://ia.goblincreative.com desde el
> 2026-09-30.

## Cómo trabajamos los despliegues

1. Se preparan los comandos agrupados por fase (preparar, desplegar, verificar).
2. Se ejecutan en el servidor y se pega la salida completa para revisarla.
3. No se pasa a la siguiente fase hasta que la anterior está revisada.
4. Cada despliegue queda anotado al final de este documento.

## Datos del servidor

| Dato | Valor |
|------|-------|
| Dominio | `ia.goblincreative.com` (también `www.ia.`) |
| IP | 107.161.187.186 (el mismo servidor que goblincreative.com) |
| Panel | cPanel (Apache con `mod_bwlimited`) |
| HTTPS | Let's Encrypt válido para `ia.` y `www.ia.`, vence 2026-12-28 (renovación automática de cPanel: confirmar) |
| Acceso | SSH (confirmado por el equipo) |
| Estado inicial | Subdominio vacío (solo `cgi-bin`), con listado de directorios visible |
| Ruta del proyecto | `~/ia.goblincreative.com` (la carpeta del dominio que creó el equipo) |
| Raíz del subdominio | `ia.goblincreative.com/web` |
| Sistema | CloudLinux 8.10, usuario `goblincr` |
| PHP | Web del subdominio: **8.4** (MultiPHP). Terminal: 8.1 por defecto → usar siempre `/opt/cpanel/ea-php84/root/usr/bin/php` |
| Base de datos | MySQL 8.0.46 |
| Herramientas | git, pdftotext, convert, crontab ✅ · Composer 2.10.3 en `~/bin/composer.phar` (verificado por SHA-256) |
| GitHub | Deploy key de solo lectura `~/.ssh/ia_goblin_deploy`, alias SSH `github-ia-goblin` ✅ |
| `allow_url_fopen` | **off** (PHP no descarga archivos: instalar herramientas con `curl`) |
| Entorno de terminal | `source ~/.ia-goblin.env` (PHP 8.4 primero en el PATH, función `composer`) |
| Base de datos | `goblincr_iagoblin`, usuario `goblincr_iagoblin` (todos los privilegios). Contraseña: solo en `settings.local.php` del servidor |
| Límites PHP (8.1) | memory_limit 128M, upload_max 2M → subir para el subdominio (256M / 32M) |

## Requisitos que el servidor debe cumplir

- PHP 8.4 con las extensiones que exige Drupal 11.
- `poppler-utils` instalado (`pdftotext`).
- Carpeta `private/` al lado de `web/`, escribible por el servidor web y fuera
  del document root.
- El document root apunta a `web/`, no a la raíz del repositorio.
- Cron del sistema ejecutando `drush cron` (el módulo usa colas para los
  webhooks).
- HTTPS: los webhooks de WhatsApp, Twilio y Evolution lo exigen.
- Salida a internet hacia `ftp.drupal.org` para descargar las traducciones al
  español (o importarlas a mano).

## Idioma: las traducciones no viajan en `config/sync`

El sitio está en español. La configuración (idioma por defecto, prefijos de
URL) sí va en `config/sync`, pero **las traducciones de la interfaz viven en
la base de datos**. Tras el primer `drush config:import` en un servidor:

```bash
drush locale:check && drush locale:update
```

Sin esto, los textos de core (errores de acceso, menús) salen en inglés.

## `settings.local.php` de producción

Se crea a mano en el servidor; nunca se sube a git. Plantilla:

```php
<?php

$databases['default']['default'] = [
  'driver' => 'mysql',
  'database' => '...',
  'username' => '...',
  'password' => '...',
  'host' => 'localhost',
  'port' => '3306',
  'prefix' => '',
];

$settings['hash_salt'] = '...';  // Valor largo y aleatorio, distinto al local.
$settings['trusted_host_patterns'] = ['^dominio\.com$'];

// Las claves del módulo (OpenAI, Twilio…) se escriben en su pantalla de
// Configuración y sobreviven a los despliegues. Solo si se quiere fijar una
// aquí (gana sobre la del formulario):
// $config['ai_whatsapp_automation.settings']['openai']['api_key'] = '...';
```

## Procedimiento

### Comprobado en local antes de desplegar (2026-09-30)

Instalación limpia desde la configuración, como en el servidor:

1. `drush site:install --existing-config` → instala, **pero** no crea el
   idioma inglés ni sus 96 traducciones de configuración (quedan 98
   diferencias en `config:status`).
2. `drush config:import -y` justo después → **0 diferencias**.
3. `drush php:script scripts/bots/goblin.php` → crea el cliente «Goblin» (no
   existe en una instalación nueva) y el bot.

### Fases en el servidor

- **Fase 0** (hecha): reconocimiento.
- **Fase 1** (hecha): PHP 8.4.25 con las extensiones de Drupal, Composer en `~/bin`, deploy key de GitHub.
- **Fase 2** (hecha): base de datos `goblincr_iagoblin`; código clonado
  (deploy key; hoy en `~/ia.goblincreative.com`); `composer install --no-dev -o`; carpeta
  `private/`; `settings.local.php` (permisos 600, fuera de git) con base de
  datos, `hash_salt`, `trusted_host_patterns` y errores ocultos.
  Contraseña de la base: la generó el servidor y se asignó con
  `uapi Mysql set_password`; solo existe en `settings.local.php`.
- **Fase 3A** (hecha): `drush si --existing-config` + `drush cim` (0
  diferencias), traducciones, `scripts/bots/goblin.php` (cliente y bot
  creados), `settings.php` en 444 y `sites/default` en 555. `drush
  core:requirements --severity=2`: sin errores.
- **Fase 3B** (hecha): proyecto movido a `~/ia.goblincreative.com` y raíz
  del subdominio → `ia.goblincreative.com/web`. PHP 8.4 y límites: handler en
  `~/ia.goblincreative.com/.htaccess`, `web/.user.ini` y `web/php.ini`
  (los tres ignorados en git).
- **Fase 3C** (hecha): verificado desde fuera (PHP 8.4.25, 600M/256M, login,
  rutas sensibles en 404), acceso admin, cron cada 5 min con drush.
  Pendiente: forzar HTTPS en cPanel y confirmar la línea del crontab.

### Desplegar una mejora

```bash
source ~/.ia-goblin.env
cd ~/ia.goblincreative.com
git pull
composer install --no-dev -o      # solo si cambió composer.lock
drush updb -y
drush cim -y
drush cr
git status --short                # debe salir vacío
```

Las claves escritas en la Configuración del módulo **no se pierden** con
`drush cim` (viven en el State, no en `config/sync`).

### Lecciones del primer despliegue

- **Respetar la estructura del servidor que ya preparó el equipo** (la
  carpeta del dominio) y preguntar antes de decidir rutas.
- Con la Terminal web de cPanel, pegar dentro de `read -s` no funciona y
  pegar varias líneas hace que `read` se trague las siguientes. Para
  secretos: generarlos en el servidor (como la contraseña de la base) o
  escribirlos en un archivo con marcador y reemplazarlo con una sola línea.
- El subdominio se apunta a Drupal **después** de instalar desde la terminal:
  si no, el instalador web queda expuesto.

## Historial de despliegues

| Fecha | Commit | Resultado | Notas |
|-------|--------|-----------|-------|
| 2026-09-30 | `4c590f2` | Instalado y funcionando | Primer despliegue (fases 0–3C) |
