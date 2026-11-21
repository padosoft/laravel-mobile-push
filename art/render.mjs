#!/usr/bin/env node
// Renders an SVG from art/ to a PNG with headless Chromium (Playwright).
//
//   node art/render.mjs                                  # banner -> resources/laravel-mobile-push-banner.png (1774x887 @2x)
//   node art/render.mjs --svg art/favicon.svg --out /tmp/fav.png --width 64 --height 64 --scale 1
//
// The SVG is drawn into a page exactly --width x --height CSS pixels and
// captured at --scale device pixels per CSS pixel. Fonts come from the
// system (fc-list); see art/README.md.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = Object.fromEntries(
    process.argv.slice(2).reduce((pairs, arg, i, all) => {
        if (arg.startsWith('--')) pairs.push([arg.slice(2), all[i + 1]]);
        return pairs;
    }, []),
);

const svgPath = resolve(root, args.svg ?? 'art/banner.svg');
const outPath = resolve(root, args.out ?? 'resources/laravel-mobile-push-banner.png');
const width = Number(args.width ?? 1774);
const height = Number(args.height ?? 887);
const scale = Number(args.scale ?? 2);

const svg = readFileSync(svgPath, 'utf8');
const html = `<!doctype html><html><head><meta charset="utf-8"><style>
html,body{margin:0;padding:0;background:transparent;overflow:hidden}
svg{display:block;width:${width}px;height:${height}px}
</style></head><body>${svg.replace(/^<\?xml[^>]*>\s*/, '')}</body></html>`;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: scale });
const problems = [];
page.on('console', (m) => { if (m.type() === 'error') problems.push(m.text()); });
page.on('pageerror', (e) => problems.push(String(e)));
await page.setContent(html, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);
await page.screenshot({ path: outPath, omitBackground: true, clip: { x: 0, y: 0, width, height } });
await browser.close();

if (problems.length) {
    console.error(problems.join('\n'));
    process.exit(1);
}
console.log(`rendered ${svgPath} -> ${outPath} (${width}x${height} @${scale}x)`);
