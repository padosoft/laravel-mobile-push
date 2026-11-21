// The root docmd.config.json mirrors docs-site/docmd.config.json (it lets docmd run from the
// repository root). Only the path-bearing keys may differ; everything else must be identical.
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const read = (p) => JSON.parse(readFileSync(resolve(here, p), 'utf8'));
const site = read('../docmd.config.json');
const root = read('../../docmd.config.json');

const strip = (c) => {
  const copy = structuredClone(c);
  delete copy.src; delete copy.out; delete copy.favicon;
  if (copy.theme) delete copy.theme.customCss;
  return copy;
};

const a = JSON.stringify(strip(site), null, 1).split('\n');
const b = JSON.stringify(strip(root), null, 1).split('\n');
const diff = [];
for (let i = 0; i < Math.max(a.length, b.length); i++) if (a[i] !== b[i]) diff.push(`  docs-site: ${a[i] ?? '(none)'}\n  root:      ${b[i] ?? '(none)'}`);
if (diff.length) {
  console.error('docmd.config.json (root) and docs-site/docmd.config.json have drifted:\n' + diff.slice(0, 6).join('\n---\n'));
  process.exit(1);
}
console.log('OK: root and docs-site docmd configs are consistent.');
