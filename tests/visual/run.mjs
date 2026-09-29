// Runs goblin-qa.mjs in every browser engine and saves a sign-in screenshot
// per engine in screenshots/ (git-ignored).
//
//   cd tests/visual && npm install && npx playwright install webkit firefox
//   GOBLIN_ULI="$(ddev drush uli --uri=http://ia-goblin.ddev.site --no-browser)" npm run qa
//
// Engines: chromium (Chrome/Edge), webkit (the Safari engine: close to
// Safari, not identical) and firefox. For the real Safari and Firefox apps
// use run-real.mjs.
//
// The admin login link (GOBLIN_ULI) is single-use, so only the first engine
// checks signed-in screens unless one link per engine is supplied as
// GOBLIN_ULI_CHROMIUM, GOBLIN_ULI_WEBKIT, GOBLIN_ULI_FIREFOX.

import { chromium, webkit, firefox } from 'playwright';
import { mkdirSync } from 'node:fs';
import qa from './goblin-qa.mjs';

const ENGINES = { chromium, webkit, firefox };
const only = process.argv.slice(2);
const shots = new URL('./screenshots/', import.meta.url).pathname;
mkdirSync(shots, { recursive: true });

let failed = false;
const sharedUli = process.env.GOBLIN_ULI;
let sharedUliUsed = false;

for (const [name, engine] of Object.entries(ENGINES)) {
  if (only.length && !only.includes(name)) continue;

  const uli = process.env[`GOBLIN_ULI_${name.toUpperCase()}`] ?? (!sharedUliUsed ? sharedUli : undefined);
  if (uli === sharedUli) sharedUliUsed = true;
  if (uli) process.env.GOBLIN_ULI = uli; else delete process.env.GOBLIN_ULI;

  const browser = await engine.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/404/.test(m.text())) errors.push(`console: ${m.text()}`); });

  const result = await qa(page);

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.context().clearCookies();
  await page.goto('http://ia-goblin.ddev.site/user/login');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: `${shots}login-${name}.png` });
  await browser.close();

  const problems = result.problems.flatMap((p) => p.problems.map((x) => `${p.role} ${p.path} @${p.width}: ${x}`));
  console.log(`\n=== ${name} (${browser.version?.() ?? ''}) — ${result.screens} screens, ${problems.length + errors.length} problems${uli ? '' : ' (signed-in screens skipped: no login link)'}`);
  for (const line of [...problems, ...errors]) console.log('  ' + line);
  if (problems.length || errors.length) failed = true;
}

process.exitCode = failed ? 1 : 0;
