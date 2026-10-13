// QA sweep of the whole panel, with each role's OWN account.
//
//   node qa-sweep.mjs            (needs DDEV running; makes one-time logins)
//
// Crawls every module page reachable from each role's start pages and
// reports, per page: HTTP status, PHP errors, Drupal error messages, broken
// images, sideways scroll, a visible way out, JavaScript errors, and links
// that lead to "Acceso denegado" for that role (a link the role cannot use
// reads as broken). Never follows links that change data (delete, close,
// stop AI…). Exits 1 when any page has a problem.

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://ia-goblin.ddev.site';
const uli = (uid) => String(execSync(`cd ../.. && ddev drush uli --uid=${uid} --uri=${BASE} --no-browser`)).trim().split('\n').pop();
const ROLES = [
  ['admin', 1, ['/admin/reports/ai-whatsapp-automation', '/admin/content/ai-whatsapp/bots', '/admin/config/services/ai-whatsapp-automation']],
  ['gestor', 4, ['/admin/content/ai-whatsapp/bots', '/admin/content/ai-whatsapp/operator-actions']],
  ['cliente', 3, ['/admin/reports/ai-whatsapp-automation']],
];
const MODULE_PATH = /^\/admin\/(content\/ai-whatsapp|reports\/ai-whatsapp|config\/services\/ai-whatsapp)/;
const CHANGES_DATA = /delete|logout|stop-ai|close|reactivate|assign|manual-reply|\/status|token=/;

const browser = await chromium.launch();
let pages = 0;
const problems = [];
for (const [role, uid, seeds] of ROLES) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const jsErrors = [];
  page.on('pageerror', (e) => jsErrors.push(e.message.slice(0, 120)));
  await page.goto(uli(uid));
  const seen = new Set();
  const queue = [...seeds];
  while (queue.length && seen.size < 80) {
    const path = queue.shift();
    if (seen.has(path)) continue;
    seen.add(path);
    jsErrors.length = 0;
    const response = await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 });
    pages++;
    const status = response.status();
    const info = await page.evaluate(() => ({
      php: /The website encountered an unexpected error|Error: |Warning: |Notice: |Deprecated: /.test(document.body.innerText),
      errors: [...document.querySelectorAll('.messages--error')].map((m) => m.innerText.slice(0, 100)),
      broken: [...document.images].filter((i) => i.complete && i.naturalWidth === 0 && i.src).map((i) => i.src.slice(-60)),
      sideways: document.documentElement.scrollWidth > innerWidth + 2,
      exit: !!document.querySelector('.aiwa-panel-nav__logout, a[href*="/user/logout"]'),
      links: [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')),
    }));
    const found = [];
    // Every page in the queue came from a link this role was shown: a 403
    // here is a link the role cannot use.
    if (status >= 400) found.push(`HTTP ${status}`);
    if (info.php) found.push('error de PHP');
    if (info.errors.length) found.push('mensaje de error: ' + info.errors.join(' / '));
    if (info.broken.length) found.push('imágenes rotas');
    if (info.sideways) found.push('desplazamiento lateral');
    // E-mail previews render the e-mail as its recipient sees it, in a new
    // tab (ClientMailForm): no panel, by design.
    if (!info.exit && !path.includes('/correos/vista-previa/')) found.push('sin salida visible');
    if (jsErrors.length) found.push('JS: ' + jsErrors.join(' / '));
    if (found.length) problems.push(`${role} ${path}: ${found.join(' | ')}`);
    for (const href of info.links) {
      const clean = href.split('?')[0].split('#')[0];
      if (MODULE_PATH.test(clean) && !CHANGES_DATA.test(href) && !seen.has(clean) && !queue.includes(clean)) queue.push(clean);
    }
  }
  console.log(`${role}: ${seen.size} páginas recorridas`);
  await context.close();
}
await browser.close();

if (problems.length) {
  console.log('PROBLEMAS\n- ' + problems.join('\n- '));
  process.exit(1);
}
console.log(`OK: ${pages} páginas sin problemas (cada enlace visible abre para su rol)`);
