# IA Goblin

Sitio Drupal 11 que aloja el módulo personalizado `ai_whatsapp_automation`:
bots de WhatsApp y chat web con OpenAI, bases de conocimiento (RAG),
prospectos y operadores humanos.

## Documentación

| Documento | Para qué |
|-----------|----------|
| [docs/bitacora.md](docs/bitacora.md) | Qué se ha hecho, por qué, y dónde quedamos |
| [docs/despliegue.md](docs/despliegue.md) | Cómo se despliega a producción |

## Stack

| Componente | Versión |
|------------|---------|
| Drupal core | 11.x (`drupal/recommended-project`) |
| PHP | 8.4 |
| Base de datos | MariaDB 11.8 |
| Entorno local | DDEV (Docker) |
| CLI | Drush 13 |

## Estructura

```
.ddev/                     Entorno Docker local (DDEV)
config/sync/               Configuración exportada del sitio (se despliega con config:import)
docs/                      Documentación del proyecto
private/                   Archivos privados, fuera de web/ (no se versiona)
web/modules/custom/        Código propio: ai_whatsapp_automation (el producto)
                           y goblin_portal (portada, acceso, redirección al panel)
web/themes/custom/goblin/  Tema del frontend (colores, logo y favicon configurables)
tests/visual/              QA visual con navegador (contraste, desbordes, móvil)
web/sites/default/settings.php        Versionado, sin secretos
web/sites/default/settings.local.php  Secretos por entorno (no se versiona)
```

## Levantar el proyecto en local

```bash
git clone git@github.com:ads-josera/ia-goblin.git
cd ia-goblin
ddev start
ddev composer install
ddev drush site:install --existing-config -y   # no comprobado aún, ver bitácora
ddev drush uli
```

Sitio: https://ia-goblin.ddev.site

## Requisitos del servidor para el módulo

- **poppler-utils** (`pdftotext`): extrae el texto de los PDF para la base de
  conocimiento. En DDEV se instala con `webimage_extra_packages`; en producción
  hay que instalarlo también.
- **Archivos privados**: `$settings['file_private_path']` apunta a
  `../private`. La carpeta debe existir y el servidor web debe poder escribir en
  ella; si no, el informe de estado marca error y los documentos de los
  clientes quedarían públicos.
- **aws/aws-sdk-php**: solo se usa si se elige el envío de correo por Amazon
  SES. Las credenciales de SES van en `settings.local.php`, nunca en la
  configuración.

## Secretos: dónde van

Las claves generales del módulo (OpenAI, token de Twilio, WhatsApp Cloud,
Evolution) **se escriben en su pantalla de Configuración**
(`/admin/config/services/ai-whatsapp-automation`). El módulo las guarda en el
State de Drupal, no en la configuración: `drush cex` no las exporta a git y
`drush cim` (cada despliegue) no las borra. El código las sigue leyendo de
`ai_whatsapp_automation.settings` como siempre
(`src/Infrastructure/Secrets/`).

Las credenciales **por cuenta de WhatsApp** (Twilio SID/token, instancia de
Evolution) siguen en cada cuenta, como antes; son contenido y tampoco viajan
con los despliegues.

Opcional: una clave escrita en `settings.local.php` gana sobre la del
formulario (así está la de pruebas en local):

```php
$config['ai_whatsapp_automation.settings']['openai']['api_key'] = '...';
```

Antes de cada commit, revisa que `config/sync/ai_whatsapp_automation.settings.yml`
tenga las claves vacías.

## Trabajo diario

```bash
ddev start / ddev stop      # encender y apagar el entorno
ddev drush cex -y           # exportar configuración tras cambiarla en la interfaz
git add -A && git commit    # código y configuración van juntos
git push
```

## Acceso

`/` lleva al inicio de sesión; tras entrar, al panel
(`/admin/reports/ai-whatsapp-automation`). Se entra con usuario **o correo**.
Detalle en [web/modules/custom/goblin_portal/README.md](web/modules/custom/goblin_portal/README.md).

El sitio está en **español**. En un servidor nuevo, tras importar la
configuración: `drush locale:check && drush locale:update`.

## Tema

El frontend usa el tema **Goblin**. Colores, logo y favicon se cambian en
Apariencia → Goblin → Settings. Detalle en
[web/themes/custom/goblin/README.md](web/themes/custom/goblin/README.md).

## Pruebas

```bash
# Módulo y tema (unitarias, kernel y funcionales)
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=http://localhost \
  vendor/bin/phpunit -c web/core web/modules/custom/ai_whatsapp_automation/tests \
  web/modules/custom/goblin_portal/tests web/themes/custom/goblin/tests'
```
