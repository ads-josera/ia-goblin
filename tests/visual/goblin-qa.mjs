// Visual QA for the Goblin theme: measures what a screenshot only suggests.
//
// Run (the runner is the browser-automation skill):
//   node ~/.claude/skills/browser-automation/browser.mjs \
//     http://ia-goblin.ddev.site/ --script tests/visual/goblin-qa.mjs
// Set GOBLIN_ULI to a `ddev drush uli --uri=http://ia-goblin.ddev.site` link
// to also check signed-in screens.
//
// Per screen and width it reports: horizontal page scroll, elements spilling
// out of their container, split words, and the EFFECTIVE colors (computed
// style, not declared tokens) with their measured contrast.

const BASE = 'http://ia-goblin.ddev.site';
const WIDTHS = [1440, 1024, 820, 390];
const ANON = ['/', '/user/login', '/user/password', '/no-such-page'];
const SIGNED_IN = ['/', '/user/1'];

export default async function run(page) {
  const report = [];
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 });
    for (const path of ANON) report.push(await inspect(page, 'anonymous', path, width));
  }

  // A failed sign-in: the error message a client sees for a wrong password.
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(BASE + '/user/login');
    await page.fill('#edit-name', 'qa-nobody');
    await page.fill('#edit-pass', 'wrong-password');
    await page.click('#user-login-form #edit-submit');
    await page.waitForSelector('.messages');
    report.push({ ...(await measure(page)), role: 'anonymous', path: '/user/login (error)', width, status: 200 });
  }

  if (process.env.GOBLIN_ULI) {
    await page.goto(process.env.GOBLIN_ULI);
    for (const width of WIDTHS) {
      await page.setViewportSize({ width, height: 900 });
      for (const path of SIGNED_IN) report.push(await inspect(page, 'admin', path, width));
    }
  }

  const problems = report.filter((r) => r.problems.length);
  return { screens: report.length, withProblems: problems.length, problems, colors: report.filter((r) => r.width === 1440) };
}

async function inspect(page, role, path, width) {
  const response = await page.goto(BASE + path);
  return { role, path, width, status: response?.status(), ...(await measure(page)) };
}

async function measure(page) {
  return page.evaluate(() => {
    const problems = [];
    const parse = (c) => (c.match(/[\d.]+/g) || []).map(Number);
    const lum = ([r, g, b]) => {
      const f = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const ratio = (a, b) => { const x = lum(parse(a)), y = lum(parse(b)); return +((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)).toFixed(2); };
    // Effective background: first ancestor with a non-transparent one.
    const bgOf = (el) => {
      for (let n = el; n; n = n.parentElement) {
        const c = getComputedStyle(n).backgroundColor;
        const a = parse(c)[3];
        if (c && !(a === 0)) return c;
      }
      return 'rgb(255, 255, 255)';
    };
    const pair = (sel, min) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const fg = getComputedStyle(el).color, bg = bgOf(el), r = ratio(fg, bg);
      if (r < min) problems.push(`contrast ${sel}: ${r}:1 < ${min}:1`);
      return { fg, bg, ratio: r };
    };

    if (document.documentElement.scrollWidth > window.innerWidth + 2) {
      problems.push(`page scrolls sideways: ${document.documentElement.scrollWidth}px > ${window.innerWidth}px`);
    }
    const vw = window.innerWidth;
    for (const el of document.body.querySelectorAll('*')) {
      const r = el.getBoundingClientRect();
      if (!r.width || r.right <= vw + 2 || el.closest('.visually-hidden')) continue;
      let scrolls = false;
      for (let p = el.parentElement; p; p = p.parentElement) {
        const ox = getComputedStyle(p).overflowX;
        if (ox === 'auto' || ox === 'scroll') { scrolls = true; break; }
      }
      if (!scrolls) problems.push(`overflow: <${el.tagName.toLowerCase()} class="${el.className}"> by ${Math.round(r.right - vw)}px`);
    }
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
      const node = walker.currentNode;
      if (node.parentElement.closest('.visually-hidden, script, style')) continue;
      for (const m of (node.nodeValue || '').matchAll(/[^\s]{4,}/g)) {
        const range = document.createRange();
        range.setStart(node, m.index);
        range.setEnd(node, m.index + m[0].length);
        const lines = new Set([...range.getClientRects()].filter((x) => x.width).map((x) => Math.round(x.top)));
        if (lines.size > 1 && !/https?:\/\/|www\.|-|@/.test(m[0])) problems.push(`split word: "${m[0]}"`);
      }
    }
    // A token that failed to load leaves these transparent.
    for (const sel of ['.site-header', '.site-footer', 'body']) {
      const el = document.querySelector(sel);
      if (el && parse(getComputedStyle(el).backgroundColor)[3] === 0) problems.push(`transparent background: ${sel}`);
    }
    const logo = document.querySelector('.site-logo img');
    return {
      title: document.title,
      h1: document.querySelector('h1')?.innerText ?? null,
      problems,
      colors: {
        body: pair('.layout-content p, .layout-content', 4.5),
        siteName: pair('.site-name a', 4.5),
        headerMenu: pair('.site-header nav a', 4.5),
        footer: pair('.site-footer__legal', 4.5),
        primaryButton: pair('.button--primary', 4.5),
        description: pair('.form-item .description', 4.5),
        link: pair('.layout-content a:not(.button)', 4.5),
        tabs: pair('.tabs a', 4.5),
        error: pair('.messages--error', 4.5),
      },
      logo: logo ? { src: logo.getAttribute('src'), height: Math.round(logo.getBoundingClientRect().height), loaded: logo.complete && logo.naturalWidth > 0 } : null,
      favicon: document.querySelector('link[rel="icon"]')?.getAttribute('href') ?? null,
    };
  });
}
