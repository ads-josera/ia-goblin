# Roles y permisos

Quién puede hacer qué en el panel de IA Goblin, y cómo se da de alta a cada
persona.

## Los tres perfiles

| Sección | Administrador | Gestor | Atención a clientes |
|---------|:---:|:---:|:---:|
| Panel (métricas) | ✅ con costos | — | ✅ solo su empresa, sin costos |
| Conversaciones | ✅ | — | ✅ solo su empresa |
| Mensajes | ✅ | — | ✅ solo su empresa |
| Prospectos | ✅ | — | ✅ solo su empresa |
| Operar conversaciones (responder, pausar/reactivar IA, cerrar, asignar) | ✅ | — | ✅ solo su empresa |
| Cambiar estado de prospectos | ✅ | — | ✅ solo su empresa |
| Bots (prompt, modelo, integración web) | ✅ | ✅ todos los clientes | — |
| Bases de conocimiento y documentos | ✅ | ✅ todos los clientes | — |
| Cuentas de WhatsApp | ✅ | ✅ todos los clientes | — |
| Conexión QR (Evolution) | ✅ | ✅ todos los clientes | — |
| Enrutamiento de bots | ✅ | ✅ | — |
| Bitácora de operadores | ✅ | ✅ solo lectura | — |
| Clientes, correos a clientes | ✅ | — | — |
| Configuración (claves de API) | ✅ | — | — |
| Usuarios | ✅ | — | — |

- **Gestor** es del equipo interno: configura lo de cualquier cliente pero no
  lee conversaciones ni mensajes, ni ve costos o claves.
- **Atención a clientes** es personal de la empresa cliente: solo ve lo de su
  empresa (el cliente asignado en su cuenta).

## Menú y salida

Gestor y Atención a clientes no ven la barra de Drupal. Tienen el **menú del
panel**: logo, las secciones que pueden abrir (solo esas), su nombre (lleva a
«Mi cuenta» para cambiar la contraseña) y **Cerrar sesión**. Los
administradores siguen con la barra lateral de Drupal.

Tras iniciar sesión cada uno llega a su primera sección: el Panel
(Atención a clientes, administradores) o Bots (Gestor).

## Dar de alta a una persona

Solo un administrador. *Personas → Añadir usuario* (`/admin/people/create`):

- **Atención a clientes:** rol «Atención a clientes (AI WhatsApp)» y elegir
  su **cliente** en el campo de AI WhatsApp. El formulario no deja guardar sin
  cliente: sin él, la persona entraría y no vería nada.
- **Gestor:** rol «Gestor (AI WhatsApp)». No lleva cliente.

La persona puede entrar con su **usuario o su correo**.

## Cómo está hecho (para desarrollo)

- Roles: `ai_whatsapp_automation/src/Access/PanelRoles.php`. Los crea
  `hook_install` y, en sitios ya instalados, `update_11039`. Sin tocar los
  permisos que un sitio haya añadido.
- Un permiso por sección: `src/Access/SectionAccess.php` (fuente única para el
  control de acceso, las rutas y los filtros de listas).
  «administer ai whatsapp automation entities» **sigue abriendo todo**, como
  antes de que existieran las secciones.
- Menú del panel: `src/Ui/PanelNavigation.php`. **Una sección nueva del
  panel hay que añadirla también ahí**, o gestores y clientes no tendrán cómo
  llegar a ella.
- Pruebas: `tests/src/Functional/Access/PanelRolesTest.php` y el recorrido
  real por rol `tests/visual/roles-walk.mjs`.
