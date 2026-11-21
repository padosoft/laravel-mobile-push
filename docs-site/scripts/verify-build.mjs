// Verifies the built site (_site): every internal link, image and #fragment resolves.
// docmd's own `validate` only reads the Markdown; this reads the generated HTML.
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';

const OUT = resolve(process.cwd(), '_site');
if (!existsSync(OUT)) { console.error('_site does not exist: run `npm run build` first.'); process.exit(1); }

const pages = [];
(function walk(d) {
  for (const n of readdirSync(d)) {
    const p = join(d, n);
    if (statSync(p).isDirectory()) { if (n !== 'okf') walk(p); } else if (n.endsWith('.html')) pages.push(p);
  }
})(OUT);

const idCache = new Map();
const idsOf = (file) => {
  if (!idCache.has(file)) idCache.set(file, new Set([...readFileSync(file, 'utf8').matchAll(/\sid="([^"]+)"/g)].map((m) => m[1])));
  return idCache.get(file);
};

const bad = [];
let checked = 0;
for (const page of pages) {
  const html = readFileSync(page, 'utf8');
  for (const m of html.matchAll(/\s(?:href|src)="([^"]*)"/g)) {
    const raw = m[1].replace(/&amp;/g, '&');
    if (!raw || /^([a-z][a-z0-9+.-]*:|\/\/)/i.test(raw) || raw.startsWith('data:')) continue;
    const [pathPart, frag] = raw.split('#');
    let target = pathPart ? resolve(dirname(page), pathPart.split('?')[0]) : page;
    if (pathPart.startsWith('/')) target = join(OUT, pathPart.split('?')[0]);
    if (!target.startsWith(OUT)) continue;
    let file = target;
    if (existsSync(file) && statSync(file).isDirectory()) file = join(file, 'index.html');
    checked++;
    if (!existsSync(file)) { bad.push(`${relative(OUT, page)}: ${raw} -> missing ${relative(OUT, file)}`); continue; }
    if (frag && file.endsWith('.html') && !idsOf(file).has(decodeURIComponent(frag))) {
      bad.push(`${relative(OUT, page)}: ${raw} -> no element with id "${frag}"`);
    }
  }
}
if (bad.length) { console.error(`Broken references in the built site (${bad.length}):\n` + bad.join('\n')); process.exit(1); }
console.log(`OK: ${pages.length} HTML pages, ${checked} internal references resolve.`);
