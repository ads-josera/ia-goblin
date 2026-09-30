# Despliegue

> **Estado:** todavía no hay servidor de producción. Este documento se completa
> en cuanto se elija, con los comandos reales que se usen y su resultado.

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
| Ruta del proyecto (propuesta) | `~/ia-goblin` (fuera de la carpeta pública) |
| Raíz del subdominio (propuesta) | `~/ia-goblin/web` |
| Sistema | CloudLinux 8.10, usuario `goblincr` |
| PHP | Web del subdominio: **8.4** (MultiPHP). Terminal: 8.1 por defecto → usar siempre `/opt/cpanel/ea-php84/root/usr/bin/php` |
| Base de datos | MySQL 8.0.46 |
| Herramientas | git, pdftotext, convert, crontab ✅ · **Composer: no** (se instala en `~/bin`) |
| GitHub | Sin acceso: se crea una llave de solo lectura (deploy key) |
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

// Credenciales del módulo: aquí, nunca en la configuración exportada.
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
- **Fase 1**: PHP 8.4, Composer en `~/bin`, deploy key de GitHub.
- **Fase 2**: base de datos (cPanel), clonar en `~/ia-goblin`, `composer
  install`, `settings.local.php`, raíz del subdominio → `~/ia-goblin/web`.
- **Fase 3**: instalar (`si --existing-config` + `cim`), traducciones, bot,
  cron, pruebas.

## Historial de despliegues

| Fecha | Commit | Resultado | Notas |
|-------|--------|-----------|-------|
| — | — | — | — |
