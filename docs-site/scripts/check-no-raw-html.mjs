// Fails when a generated page contains raw HTML or component-style tags outside code.
// docmd escapes HTML (security.html = "escape") instead of rendering it, so a stray tag
// would show up as literal text or vanish; the sync script turns prose `<token>` into
// entities, and this check makes sure nothing slipped through. Comments such as the
// `<!-- example: id -->` markers must be gone too.
import { readdirSync, statSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const DOCS = join(process.cwd(), 'docs');
const TAG = /<\/?[A-Za-z][^<>\n]*>|<!--/g;
const bad = [];

function proseOf(line) {
  return line.replace(/(`+).+?\1/g, '');
}

(function walk(d) {
  for (const n of readdirSync(d)) {
    const p = join(d, n);
    if (statSync(p).isDirectory()) { walk(p); continue; }
    if (!n.endsWith('.md')) continue;
    let fence = null;
    let fm = false; // inside the YAML frontmatter
    readFileSync(p, 'utf8').split('\n').forEach((l, i) => {
      if (i === 0 && l === '---') { fm = true; return; }
      if (fm) { if (l === '---') fm = false; return; }
      const f = l.match(/^\s*(```+|~~~+)/);
      if (f) { fence = fence ? (fence === f[1][0] ? null : fence) : f[1][0]; return; }
      if (fence) return;
      const m = proseOf(l).match(TAG);
      if (m) {
        const real = m.filter((t) => !/^<[A-Za-z][A-Za-z0-9+.-]*:[^\s>]*>$/.test(t) && !/^<[^\s@>]+@[^\s@>]+>$/.test(t));
        if (real.length) bad.push(`${p}:${i + 1} ${real.join(' ')}`);
      }
    });
  }
})(DOCS);

if (bad.length) {
  console.error('Raw HTML found in generated pages:\n' + bad.join('\n'));
  process.exit(1);
}
console.log('OK: no raw HTML in the pages.');
