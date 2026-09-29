// Checks the sign-in screen in the REAL Safari and Firefox apps (WebDriver).
//
//   cd tests/visual && npm run qa:real            # both
//   node run-real.mjs firefox                     # one
//
// One-time setup:
// - Firefox: `brew install geckodriver`.
// - Safari: Safari → Settings → Advanced → "Show features for web
//   developers", then Develop → "Allow Remote Automation", and in a terminal
//   `sudo safaridriver --enable` (asks for the Mac admin password).
//
// Safari opens a visible, separate automation window; it never touches your
// own tabs or sessions. Screenshots go to screenshots/real-<browser>.png.

import { Builder } from 'selenium-webdriver';
import firefox from 'selenium-webdriver/firefox.js';
import { mkdirSync, writeFileSync } from 'node:fs';

const URL_LOGIN = 'http://ia-goblin.ddev.site/';
const shots = new URL('./screenshots/', import.meta.url).pathname;
mkdirSync(shots, { recursive: true });

const only = process.argv.slice(2);
const targets = ['firefox', 'safari'].filter((b) => !only.length || only.includes(b));

// Measures the same things the engine QA relies on, in the real browser.
const MEASURE = `
  const lum = (c) => { const [r, g, b] = c.match(/[\\d.]+/g).map(Number).map(v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return +((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)).toFixed(2); };
  const btn = document.querySelector('.button--large');
  const bs = getComputedStyle(btn);
  const art = getComputedStyle(document.querySelector('.auth__art'));
  return {
    path: location.pathname,
    font: getComputedStyle(document.body).fontFamily.split(',')[0].trim(),
    poppinsLoaded: document.fonts.check('16px Poppins'),
    button: { size: bs.fontSize, weight: bs.fontWeight, ratio: ratio(bs.color, bs.backgroundColor) },
    artImage: art.backgroundImage.includes('auth-background.webp') ? 'webp' : art.backgroundImage.includes('auth-background.jpg') ? 'jpg' : art.backgroundImage,
    logoLoaded: (() => { const i = document.querySelector('.auth__logo'); return !!i && i.complete && i.naturalWidth > 0; })(),
    sideScroll: document.documentElement.scrollWidth > innerWidth,
  };
`;

let failed = false;
for (const name of targets) {
  let driver;
  try {
    const builder = new Builder().forBrowser(name);
    if (name === 'firefox') {
      builder.setFirefoxOptions(new firefox.Options().setBinary('/Applications/Firefox.app/Contents/MacOS/firefox').addArguments('-headless'));
    }
    driver = await builder.build();
    await driver.manage().window().setRect({ width: 1440, height: 900 });
    await driver.get(URL_LOGIN);
    await driver.wait(async () => (await driver.executeScript('return document.fonts.status')) === 'loaded', 10000);
    const caps = await driver.getCapabilities();
    const m = await driver.executeScript(MEASURE);
    writeFileSync(`${shots}real-${name}.png`, await driver.takeScreenshot(), 'base64');

    const problems = [];
    if (m.path !== '/user/login') problems.push(`front page did not redirect to /user/login (got ${m.path})`);
    if (!m.poppinsLoaded) problems.push('Poppins not loaded');
    if (parseFloat(m.button.size) < 18.66 || parseInt(m.button.weight, 10) < 700) problems.push(`button label not large text: ${m.button.size}/${m.button.weight}`);
    if (m.button.ratio < 3) problems.push(`button contrast ${m.button.ratio}:1`);
    if (!m.logoLoaded) problems.push('logo not loaded');
    if (m.sideScroll) problems.push('page scrolls sideways');

    console.log(`\n=== ${name} ${caps.get('browserVersion')} — ${problems.length} problems`);
    console.log('  ' + JSON.stringify(m));
    for (const p of problems) console.log('  ✗ ' + p);
    if (problems.length) failed = true;
  }
  catch (e) {
    failed = true;
    console.log(`\n=== ${name} — could not run: ${e.message.split('\n')[0]}`);
    if (name === 'safari') console.log('  Enable it once: Develop → Allow Remote Automation, then `sudo safaridriver --enable`.');
  }
  finally {
    await driver?.quit();
  }
}

process.exitCode = failed ? 1 : 0;
