# IA Goblin

Drupal 11 site that hosts the `ai_whatsapp_automation` custom module
(WhatsApp/web chat bots with OpenAI, RAG knowledge bases, leads and human
operators).

## Stack

| Component | Version |
|-----------|---------|
| Drupal core | 11.x (`drupal/recommended-project`) |
| PHP | 8.4 |
| Database | MariaDB 11.8 |
| Local environment | DDEV (Docker) |
| CLI | Drush 13 |

## Layout

```
.ddev/                     Local Docker environment (DDEV)
config/sync/               Exported site configuration (deployed with config:import)
private/                   Private files, outside the web root (not versioned)
web/modules/custom/        Custom code — ai_whatsapp_automation lives here
web/sites/default/settings.php        Versioned, no secrets
web/sites/default/settings.local.php  Per-environment secrets (not versioned)
```

## Local setup

```bash
git clone git@github.com:ads-josera/ia-goblin.git
cd ia-goblin
ddev start
ddev composer install
ddev drush site:install --existing-config -y
ddev drush uli
```

Site: https://ia-goblin.ddev.site

## Server requirements for the module

- **poppler-utils** (`pdftotext`) — PDF extraction for RAG. Installed in DDEV
  through `webimage_extra_packages`; must be installed on production too.
- **Private file system** — `$settings['file_private_path']` points to
  `../private`. The directory must exist and be writable by the web server,
  otherwise the status report shows an error and client documents would be
  public.
- **aws/aws-sdk-php** — only used when the Amazon SES mail backend is selected.
  SES credentials go in `settings.local.php`, never in configuration.

## Daily workflow

```bash
ddev drush cex -y          # export config after changing it in the UI
git add -A && git commit    # commit code + config together
```

## Tests

```bash
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=http://web \
  vendor/bin/phpunit -c web/core web/modules/custom/ai_whatsapp_automation/tests'
```
