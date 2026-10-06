# Acuerdos de trabajo — IA Goblin

- Al empezar cada sesión, **cargar la skill `jarvis`** antes de cualquier
  trabajo (decisión del equipo, 2026-10-05).
- Al empezar, leer [docs/bitacora.md](docs/bitacora.md): la sección «Dónde
  quedamos» dice el estado actual y los pendientes.
- Todo trabajo se documenta: una entrada nueva en la bitácora (qué, por qué,
  comprobado / no comprobado) y actualizar «Dónde quedamos» y «Pendientes».
- Despliegues: comandos agrupados por fase; el equipo los ejecuta en el
  servidor y pega la salida para revisarla. Todo queda en
  [docs/despliegue.md](docs/despliegue.md).
- Commits firmados por el equipo. El trailer es siempre
  `Co-Authored-By: Josera mkt <ads@josera.com.mx>`; nunca otra atribución.
- Documentación en español; código y comentarios de código en inglés, como el
  módulo existente.
- Secretos nunca en git: van en `settings.local.php`. Revisar
  `config/sync/ai_whatsapp_automation.settings.yml` antes de cada commit.
- Entorno local: DDEV (`ddev start`, `ddev drush ...`). Pruebas: ver README.
