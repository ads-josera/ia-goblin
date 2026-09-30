// Walks the panel with each role's OWN account: sign in, land, open every
// section of their menu and a detail page, check the sections they must NOT
// open, look at the menu on a phone, and sign out from the menu.
//
//   ROLE_CLIENT_ID=... ROLE_CLIENT_PASS=... ROLE_MANAGER_ID=... ROLE_MANAGER_PASS=... \
//     node roles-walk.mjs [chromium|webkit|firefox]
//
// Needs demo data: a client with one conversation, one lead and one bot, a
// user of that client (client agent role) and a manager user.

import { chromium, webkit, firefox } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = 'http://ia-goblin.ddev.site';
const ENGINE = { chromium, webkit, firefox }[process.argv[2] ?? 'chromium'];
const shots = new URL('./screenshots/', import.meta.url).pathname;
mkdirSync(shots, { recursive: true });

const ROLES = {
  client: {
    id: process.env.ROLE_CLIENT_ID,
    pass: process.env.ROLE_CLIENT_PASS,
    lands: '/admin/reports/ai-whatsapp-automation',
    menu: ['Panel', 'Conversaciones', 'Prospectos', 'Mensajes'],
    details: ['/admin/content/ai-whatsapp/conversations/1'],
    forbidden: ['/admin/content/ai-whatsapp/bots', '/admin/content/ai-whatsapp/accounts', '/admin/content/ai-whatsapp/knowledge-bases', '/admin/content/ai-whatsapp/routing', '/admin/content/ai-whatsapp/operator-actions', '/admin/content/ai-whatsapp/clients', '/admin/config/services/ai-whatsapp-automation', '/admin/people'],
  },
  manager: {
    id: process.env.ROLE_MANAGER_ID,
    pass: process.env.ROLE_MANAGER_PASS,
    lands: '/admin/content/ai-whatsapp/bots',
    menu: ['Bots', 'Bases de conocimiento', 'Documentos', 'Cuentas de WhatsApp', 'Conexión QR', 'Enrutamiento', 'Bitácora'],
    details: ['/admin/content/ai-whatsapp/bots/1/edit'],
    forbidden: ['/admin/reports/ai-whatsapp-automation', '/admin/content/ai-whatsapp/conversations', '/admin/content/ai-whatsapp/messages', '/admin/content/ai-whatsapp/leads', '/admin/content/ai-whatsapp/clients', '/admin/config/services/ai-whatsapp-automation', '/admin/content/ai-whatsapp/conversations/1', '/admin/people'],
  },
};

// Same page checks as goblin-qa.mjs, trimmed to what the panel needs.
const inspect = () => {
  const problems = [];
  if (document.documentElement.scrollWidth > innerWidth + 2) problems.push(`page scrolls sideways (${document.documentElement.scrollWidth}px)`);
  const nav = document.querySelector('.aiwa-panel-nav');
  if (!nav) problems.push('no panel menu');
  const logout = nav?.querySelector('.aiwa-panel-nav__logout');
  if (!logout || !logout.getBoundingClientRect().width) problems.push('no visible sign-out');
  for (const a of nav?.querySelectorAll('a') ?? []) {
    const r = a.getBoundingClientRect();
    if (r.width && r.right > innerWidth + 2 && !a.closest('.aiwa-panel-nav__list')) problems.push(`menu item off screen: ${a.innerText}`);
  }
  return {
    h1: document.querySelector('h1')?.innerText ?? null,
    menu: [...(nav?.querySelectorAll('.aiwa-panel-nav__link') ?? [])].map((a) => a.innerText.trim()),
    active: nav?.querySelector('.aiwa-panel-nav__link.is-active')?.innerText.trim() ?? null,
    drupalToolbar: !!document.querySelector('#toolbar-administration, .admin-toolbar'),
    problems,
  };
};

const report = {};
let failed = false;
const browser = await ENGINE.launch();

for (const [role, spec] of Object.entries(ROLES)) {
  const out = (report[role] = { problems: [] });
  const fail = (m) => { out.problems.push(m); failed = true; };
  const page = await browser.newPage({ viewport: { width: 1280, height: 860 } });

  await page.goto(BASE + '/');
  await page.fill('#edit-name', spec.id);
  await page.fill('#edit-pass', spec.pass);
  await page.click('#user-login-form #edit-submit');
  await page.waitForLoadState('networkidle');
  out.landed = new URL(page.url()).pathname;
  if (out.landed !== spec.lands) fail(`landed on ${out.landed}, expected ${spec.lands}`);
  const first = await page.evaluate(inspect);
  out.menu = first.menu;
  if (JSON.stringify(first.menu) !== JSON.stringify(spec.menu)) fail(`menu is [${first.menu}], expected [${spec.menu}]`);
  if (first.drupalToolbar) fail('Drupal toolbar/navigation is visible');
  await page.screenshot({ path: `${shots}role-${role}-landing.png` });

  // Every section of the menu, clicked like a person would.
  out.sections = {};
  for (const label of first.menu) {
    await page.locator('.aiwa-panel-nav__link', { hasText: new RegExp(`^${label}$`) }).click();
    await page.waitForLoadState('networkidle');
    const s = await page.evaluate(inspect);
    out.sections[label] = { path: new URL(page.url()).pathname, h1: s.h1, active: s.active };
    if (s.active !== label) fail(`${label}: active item is ${s.active}`);
    s.problems.forEach((p) => fail(`${label}: ${p}`));
  }

  // A detail page keeps the menu (and its section highlighted), then back.
  for (const path of spec.details) {
    const r = await page.goto(BASE + path);
    const s = await page.evaluate(inspect);
    out.sections[path] = { status: r.status(), h1: s.h1, active: s.active };
    if (r.status() !== 200) fail(`${path}: HTTP ${r.status()}`);
    s.problems.forEach((p) => fail(`${path}: ${p}`));
    await page.goBack();
  }

  // What this role must not open.
  out.forbidden = {};
  for (const path of spec.forbidden) {
    const r = await page.goto(BASE + path);
    out.forbidden[path] = r.status();
    if (r.status() !== 403) fail(`${path} should be 403, got ${r.status()}`);
  }

  // The menu on a phone.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE + spec.lands);
  const phone = await page.evaluate(inspect);
  phone.problems.forEach((p) => fail(`phone: ${p}`));
  await page.screenshot({ path: `${shots}role-${role}-phone.png` });

  // Sign out from the menu: must end on the sign-in form, signed out.
  await page.click('.aiwa-panel-nav__logout');
  await page.waitForLoadState('networkidle');
  out.afterLogout = new URL(page.url()).pathname;
  const back = await page.goto(BASE + spec.lands);
  out.afterLogoutAccess = back.status();
  if (out.afterLogoutAccess !== 403) fail(`still signed in after sign-out (HTTP ${out.afterLogoutAccess})`);
  await page.close();
}

await browser.close();
console.log(JSON.stringify(report, null, 1));
process.exitCode = failed ? 1 : 0;
