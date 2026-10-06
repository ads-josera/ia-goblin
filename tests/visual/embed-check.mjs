// Checks the floating chat button (js/web-chat-embed.js) on a HOSTILE host
// page: one that styles every button, span, svg, img and iframe its own way,
// as client sites do (goblincreative.com gives all buttons padding and
// inline-block, which drew the old icon 12.5px off centre).
//
//   ENGINE=chromium|webkit|firefox node embed-check.mjs [token] [bot id]
//
// For each icon option: the icon is centred (<= 0.5px), keeps its size, the
// button has its accessible name, opens and closes the chat. Changes the
// bot's icon setting while it runs and puts "chat" back at the end. Exits 1
// on any failure.

import { chromium, webkit, firefox } from 'playwright';
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';

const BASE = 'http://ia-goblin.ddev.site';
const TOKEN = process.argv[2] ?? '0f76fd51-b1a9-47cc-8dfc-6ccedab5ecd8';
const BOT = process.argv[3] ?? '3';
// The custom icon: a copy of the theme logo as a file entity (made once).
const ICON_FILE = `cd ../.. && ddev drush php:eval '$fs=\\Drupal::service("file_system"); $d="public://ai-whatsapp-widget-icons"; $fs->prepareDirectory($d, 1); $u=$fs->copy("themes/custom/goblin/logo.svg", "$d/embed-check.svg", 1); $f=\\Drupal::entityTypeManager()->getStorage("file")->loadByProperties(["uri"=>$u]); $f=reset($f) ?: \\Drupal\\file\\Entity\\File::create(["uri"=>$u,"status"=>1]); $f->save(); echo $f->id();'`;
const shots = new URL('./screenshots/', import.meta.url).pathname;
mkdirSync(shots, { recursive: true });

const iconFile = String(execSync(ICON_FILE)).trim();
const setIcon = (icon, file) => execSync(
  `cd ../.. && ddev drush php:eval '$b=\\Drupal::entityTypeManager()->getStorage("ai_whatsapp_bot")->load(${BOT}); $b->set("web_widget_icon","${icon}")->set("web_widget_button_icon_file", ${file || 'NULL'})->save();'`,
);

const HOSTILE = `<!doctype html><html><head><style>
  body{background:#2b2f33;margin:0;height:900px}
  button{padding:1px 6px;display:inline-block;line-height:normal;font-size:13px;text-align:left}
  span{display:block;margin:10px}
  svg{width:100px!important;height:100px}
  img{max-width:none;width:300px;border:5px solid red}
  iframe{border:10px solid red}
</style></head><body><p>Client page</p>
<script src="${BASE}/ai-whatsapp-automation/embed/${TOKEN}"></script></body></html>`;

const CASES = [
  { icon: 'chat', file: '', expect: 'svg' },
  { icon: 'sparkles', file: '', expect: 'svg' },
  { icon: 'help', file: '', expect: 'svg' },
  { icon: 'custom', file: iconFile, expect: 'img', white: true },
  // Nothing uploaded: falls back to the chat bubble.
  { icon: 'custom', file: '', expect: 'svg' },
];

const failures = [];
const check = (ok, message) => { if (!ok) failures.push(message); };
const ENGINE = process.env.ENGINE ?? 'chromium';
const browser = await ({ chromium, webkit, firefox })[ENGINE].launch();
try {
  for (const c of CASES) {
    setIcon(c.icon, c.file);
    const name = `${c.icon}${c.icon === 'custom' && !c.file ? '-sin-imagen' : ''}`;
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.setContent(HOSTILE, { waitUntil: 'networkidle' });
    const button = page.locator('[data-aiwa-widget-root] >> css=button');
    await button.waitFor();
    const measure = () => button.evaluate((el) => {
      const icon = el.firstElementChild;
      const r = el.getBoundingClientRect();
      const i = icon.getBoundingClientRect();
      return {
        tag: icon.tagName.toLowerCase(),
        size: [r.width, r.height],
        iconWidth: Math.round(i.width),
        dx: Math.abs((i.left + i.width / 2) - (r.left + r.width / 2)),
        dy: Math.abs((i.top + i.height / 2) - (r.top + r.height / 2)),
        label: el.getAttribute('aria-label'),
        expanded: el.getAttribute('aria-expanded'),
        background: getComputedStyle(el).backgroundColor,
      };
    });

    const closed = await measure();
    await button.screenshot({ path: `${shots}flotante-${name}.png` });
    check(closed.tag === c.expect, `${name}: icon is <${closed.tag}>, expected <${c.expect}>`);
    check(closed.size[0] === 60 && closed.size[1] === 60, `${name}: button is ${closed.size.join('x')}, expected 60x60`);
    check(closed.dx <= 0.5 && closed.dy <= 0.5, `${name}: icon off centre by ${closed.dx.toFixed(1)},${closed.dy.toFixed(1)}px`);
    check(closed.iconWidth <= 40, `${name}: the host page resized the icon to ${closed.iconWidth}px`);
    check(/^Abrir chat con /.test(closed.label ?? ''), `${name}: accessible name is "${closed.label}"`);
    check(closed.expanded === 'false', `${name}: aria-expanded is ${closed.expanded} while closed`);
    check(c.white ? closed.background === 'rgb(255, 255, 255)' : closed.background !== 'rgb(255, 255, 255)', `${name}: background ${closed.background}`);

    await button.click();
    const open = await measure();
    const frame = await page.locator('[data-aiwa-widget-root] >> css=iframe').evaluate((f) => ({
      visible: !f.hidden && f.getBoundingClientRect().height > 0,
      border: getComputedStyle(f).borderTopWidth,
    }));
    await page.screenshot({ path: `${shots}flotante-abierto-${name}.png` });
    check(frame.visible, `${name}: the chat did not open`);
    check(frame.border === '0px', `${name}: the host page put a ${frame.border} border on the chat`);
    check(open.expanded === 'true' && open.label === 'Cerrar chat', `${name}: open state is "${open.label}" / ${open.expanded}`);
    check(open.dx <= 0.5 && open.dy <= 0.5, `${name}: close icon off centre`);

    await button.click();
    check((await measure()).expanded === 'false', `${name}: the chat did not close`);
    await page.close();
  }
}
finally {
  setIcon('chat', '');
  await browser.close();
}

if (failures.length) {
  console.log(`FAIL (${ENGINE})\n- ` + failures.join('\n- '));
  process.exit(1);
}
console.log(`OK (${ENGINE}): ${CASES.length} icon cases centred, sized, labelled, open and close (screenshots in ${shots})`);
