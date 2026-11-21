#!/usr/bin/env node
/**
 * Generates docs-site/docs/** from the repository's own documentation
 * (README.md, docs/*.md, ...) according to docs-site/docs.manifest.json.
 *
 *   node scripts/sync-docs.mjs                  write the pages, assets and navigation
 *   node scripts/sync-docs.mjs --check          fail when the committed output differs
 *   node scripts/sync-docs.mjs --check --strict ... and fail on pending sources/assets and warnings
 *
 * A source that does not exist yet is "pending": a stub page is generated so the
 * navigation and the links keep working. Pending never fails a normal sync or a
 * non-strict check; --strict (used by the final pass) fails on it.
 *
 * No dependency beyond Node itself.
 */
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync, copyFileSync } from 'node:fs';
import { dirname, join, posix, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SITE = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const ROOT = resolve(SITE, '..');
const OUT_DOCS = join(SITE, 'docs');
const OUT_ASSETS = join(SITE, 'assets');
const CONFIGS = [join(SITE, 'docmd.config.json'), join(ROOT, 'docmd.config.json')];

const args = new Set(process.argv.slice(2));
const CHECK = args.has('--check');
const STRICT = args.has('--strict');

const manifest = JSON.parse(readFileSync(join(SITE, 'docs.manifest.json'), 'utf8'));
const REPO = manifest.repository.url.replace(/\/$/, '');
const BRANCH = manifest.repository.branch;

const errors = []; // always fatal
const warnings = []; // fatal with --strict
const pending = []; // missing sources and assets: fatal with --strict

// ---------------------------------------------------------------- manifest model

/** Flatten the manifest into pages with their route and output file. */
function collectPages() {
  const pages = [];
  const seenRoutes = new Set();
  const seenSources = new Set();
  const walk = (items, routeParts, navPath) => {
    for (const item of items) {
      if (item.group) {
        walk(item.items, [...routeParts, item.slug], [...navPath, item.group]);
        continue;
      }
      const route = '/' + [...routeParts, item.slug].join('/');
      if (seenRoutes.has(route)) errors.push(`manifest: duplicate route ${route}`);
      if (seenSources.has(item.source)) errors.push(`manifest: ${item.source} is listed twice`);
      seenRoutes.add(route);
      seenSources.add(item.source);
      pages.push({
        ...item,
        route,
        outFile: join(OUT_DOCS, ...routeParts, item.slug + '.md'),
        exists: existsSync(join(ROOT, item.source)),
      });
    }
  };
  for (const section of manifest.sections) walk(section.items, [section.slug], [section.title]);
  return pages;
}

const pages = collectPages();
const pageBySource = new Map(pages.map((p) => [p.source, p]));

function isExcluded(source) {
  return manifest.exclude.some((e) =>
    e.source.endsWith('/**') ? source.startsWith(e.source.slice(0, -2)) : e.source === source,
  );
}

function walkFiles(dir, base = dir) {
  const out = [];
  if (!existsSync(dir)) return out;
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...walkFiles(p, base));
    else out.push(relative(base, p).split('\\').join('/'));
  }
  return out;
}

/** Every public Markdown source must be published or explicitly excluded. */
function checkCoverage() {
  for (const file of walkFiles(join(ROOT, 'docs')).filter((f) => f.endsWith('.md'))) {
    const source = 'docs/' + file;
    if (!pageBySource.has(source) && !isExcluded(source)) {
      errors.push(`${source} is neither in the manifest nor excluded: add it to docs-site/docs.manifest.json`);
    }
  }
  for (const e of manifest.exclude) {
    if (e.source.endsWith('/**')) continue; // a directory pattern may be empty
    if (!existsSync(join(ROOT, e.source))) errors.push(`manifest exclude names a file that does not exist: ${e.source}`);
  }
  for (const p of pages) {
    if (isExcluded(p.source)) errors.push(`manifest: ${p.source} is both published and excluded`);
    if (!p.exists) pending.push(`page ${p.source} (${p.route}) does not exist yet`);
  }
  for (const a of manifest.assets) {
    if (!existsSync(join(ROOT, a.from))) pending.push(`asset ${a.from} does not exist yet`);
  }
}

// ---------------------------------------------------------------- markdown helpers

const FENCE = /^\s*(```+|~~~+)/;

/** Split a line into [{code:boolean,text}] segments on inline code spans. */
function splitCode(line) {
  const out = [];
  let i = 0;
  let buf = '';
  while (i < line.length) {
    if (line[i] === '`') {
      let n = 0;
      while (line[i + n] === '`') n++;
      const close = findRun(line, i + n, n);
      if (close === -1) {
        buf += line.slice(i, i + n);
        i += n;
        continue;
      }
      if (buf) out.push({ code: false, text: buf });
      buf = '';
      out.push({ code: true, text: line.slice(i, close + n) });
      i = close + n;
    } else {
      buf += line[i++];
    }
  }
  if (buf) out.push({ code: false, text: buf });
  return out;
}

function findRun(line, from, n) {
  let i = from;
  while (i < line.length) {
    if (line[i] === '`') {
      let k = 0;
      while (line[i + k] === '`') k++;
      if (k === n) return i;
      i += k;
    } else i++;
  }
  return -1;
}

/** Apply fn to the prose of every line (not fences, not inline code). */
function mapProse(lines, fn) {
  let fence = null;
  return lines.map((line) => {
    const m = line.match(FENCE);
    if (m) {
      const marker = m[1][0];
      if (!fence) fence = marker;
      else if (fence === marker) fence = null;
      return line;
    }
    if (fence) return line;
    // Mask inline code spans so link labels such as [`x`](y.md) stay in one piece.
    const spans = [];
    const masked = splitCode(line)
      .map((seg) => {
        if (!seg.code) return seg.text;
        spans.push(seg.text);
        return `\u0000${spans.length - 1}\u0000`;
      })
      .join('');
    return fn(masked).replace(/\u0000(\d+)\u0000/g, (_, n) => spans[Number(n)]);
  });
}

/** Remove <!-- comments --> (including the `<!-- example: id -->` markers) outside fences. */
function stripComments(lines) {
  const out = [];
  let fence = null;
  let inComment = false;
  for (let line of lines) {
    if (!inComment) {
      const m = line.match(FENCE);
      if (m) {
        const marker = m[1][0];
        if (!fence) fence = marker;
        else if (fence === marker) fence = null;
        out.push(line);
        continue;
      }
      if (fence) {
        out.push(line);
        continue;
      }
    }
    const original = line;
    let result = '';
    while (line.length) {
      if (inComment) {
        const end = line.indexOf('-->');
        if (end === -1) {
          line = '';
          break;
        }
        line = line.slice(end + 3);
        inComment = false;
      } else {
        const start = line.indexOf('<!--');
        if (start === -1) {
          result += line;
          line = '';
          break;
        }
        result += line.slice(0, start);
        line = line.slice(start + 4);
        inComment = true;
      }
    }
    if (original.includes('<!--') || original.includes('-->') || inComment) {
      if (result.trim() === '') continue; // the line was only a comment
    }
    out.push(result);
  }
  return out;
}

const ADMONITIONS = {
  NOTE: ['info', 'Note'],
  TIP: ['tip', 'Tip'],
  IMPORTANT: ['info', 'Important'],
  WARNING: ['warning', 'Warning'],
  CAUTION: ['danger', 'Caution'],
};

/** GitHub-style `> [!NOTE]` blocks become docmd callouts (docmd does not render them). */
function convertAdmonitions(lines) {
  const out = [];
  let fence = null;
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const f = line.match(FENCE);
    if (f) {
      const marker = f[1][0];
      if (!fence) fence = marker;
      else if (fence === marker) fence = null;
    }
    const m = !fence && line.match(/^>\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(.*)$/);
    if (!m) {
      out.push(line);
      continue;
    }
    const [type, title] = ADMONITIONS[m[1]];
    const body = [];
    if (m[2]) body.push(m[2]);
    let j = i + 1;
    while (j < lines.length && /^>/.test(lines[j])) {
      body.push(lines[j].replace(/^>\s?/, ''));
      j++;
    }
    out.push(`::: callout ${type} "${title}"`, ...body, ':::');
    i = j - 1;
  }
  return out;
}

/** Entities for anything that would be read as an HTML tag in prose. */
function escapeHtmlInProse(lines) {
  return mapProse(lines, (text) =>
    text.replace(/<(\/?[A-Za-z][^<>\n]*)>/g, (all, inner) => {
      if (/^[A-Za-z][A-Za-z0-9+.-]*:/.test(inner) || /^[^\s@]+@[^\s@]+$/.test(inner)) return all; // autolink
      return `&lt;${inner}&gt;`;
    }),
  );
}

// ---------------------------------------------------------------- headings and anchors

function plainHeading(raw) {
  return raw
    .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/[`*_]/g, '')
    .replace(/\s+#+\s*$/, '')
    .trim();
}

function ghSlug(raw) {
  return plainHeading(raw)
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\p{M}_\- ]/gu, '')
    .replace(/ /g, '-');
}

/** Same algorithm as @docmd/parser (hierarchical ids), applied to a page body. */
function docmdSlug(content) {
  return content
    .toLowerCase()
    .trim()
    .replace(/\s+/g, '-')
    .replace(/[^\p{L}\p{N}-]+/gu, '')
    .replace(/--+/g, '-')
    .replace(/^-+/, '')
    .replace(/-+$/, '');
}

/** [{level, raw, gh, id}] in document order; gh is GitHub's anchor, id is docmd's. */
function headingsOf(lines) {
  const out = [];
  const last = new Array(7).fill(null);
  const used = new Map();
  const ghUsed = new Map();
  let fence = null;
  let container = 0;
  for (const line of lines) {
    const f = line.match(FENCE);
    if (f) {
      const marker = f[1][0];
      if (!fence) fence = marker;
      else if (fence === marker) fence = null;
      continue;
    }
    if (fence) continue;
    if (/^:::\s*\S/.test(line)) container++;
    else if (/^:::\s*$/.test(line)) container = Math.max(0, container - 1);
    const h = line.match(/^(#{1,6})\s+(.+?)\s*#*\s*$/);
    if (!h) continue;
    const level = h[1].length;
    const raw = h[2];
    let gh = ghSlug(raw);
    const n = ghUsed.get(gh) ?? 0;
    ghUsed.set(gh, n + 1);
    if (n > 0) gh = `${gh}-${n}`;
    let id = null;
    const slug = docmdSlug(raw);
    if (slug && container === 0) {
      let parent = null;
      for (let j = level - 1; j >= 1; j--) {
        if (last[j]) {
          parent = last[j];
          break;
        }
      }
      id = parent ? `${parent}-${slug}` : slug;
      if (used.has(id)) {
        const c = used.get(id);
        used.set(id, c + 1);
        id = `${id}-${c}`;
      } else used.set(id, 1);
      last[level] = id;
      for (let j = level + 1; j <= 6; j++) last[j] = null;
    }
    out.push({ level, raw, gh, id });
  }
  return out;
}

// ---------------------------------------------------------------- page transform

function readSource(page) {
  return readFileSync(join(ROOT, page.source), 'utf8').replace(/\r\n/g, '\n').split('\n');
}

/** Source lines after the structural conversions that do not depend on link targets. */
function prepare(lines) {
  return convertAdmonitions(stripComments(lines));
}

const headingCache = new Map();
function pageHeadings(page) {
  if (!headingCache.has(page.source)) {
    headingCache.set(page.source, page.exists ? headingsOf(prepare(readSource(page))) : null);
  }
  return headingCache.get(page.source);
}

function blobUrl(repoPath, isDir) {
  return `${REPO}/${isDir ? 'tree' : 'blob'}/${BRANCH}/${repoPath}`;
}

const IMAGE = /\.(png|jpe?g|gif|svg|webp)$/i;

function rewriteTarget(target, fromPage, isImage) {
  if (/^[A-Za-z][A-Za-z0-9+.-]*:/.test(target) || target.startsWith('//')) return target;
  const hashAt = target.indexOf('#');
  const pathPart = hashAt === -1 ? target : target.slice(0, hashAt);
  const frag = hashAt === -1 ? '' : target.slice(hashAt + 1);

  let destPage = fromPage;
  let repoPath = fromPage.source;
  if (pathPart) {
    repoPath = posix.normalize(posix.join(posix.dirname(fromPage.source), decodeURI(pathPart)));
    if (repoPath.startsWith('..')) {
      warnings.push(`${fromPage.source}: link ${target} leaves the repository`);
      return target;
    }
    destPage = pageBySource.get(repoPath.replace(/\/$/, ''));
  }

  if (destPage) {
    let hash = '';
    if (frag) {
      const heads = pageHeadings(destPage);
      if (!heads) hash = '#' + frag; // pending page: cannot verify
      else {
        const h = heads.find((x) => x.gh === frag.toLowerCase());
        if (!h || !h.id) warnings.push(`${fromPage.source}: anchor #${frag} not found in ${destPage.source}`);
        hash = '#' + (h && h.id ? h.id : frag);
      }
    }
    return destPage.route + hash;
  }

  const asset = manifest.assets.find((a) => a.from === repoPath);
  if (asset) return '/assets/' + asset.to;

  const abs = join(ROOT, repoPath);
  if (!existsSync(abs)) {
    warnings.push(`${fromPage.source}: link target ${target} does not exist in the repository`);
    return target;
  }
  const dir = statSync(abs).isDirectory();
  let url = blobUrl(repoPath.replace(/\/$/, ''), dir);
  if (isImage && IMAGE.test(repoPath)) url += '?raw=true';
  return url + (frag ? '#' + frag : '');
}

const LINK = /(!?)\[((?:[^\[\]]|\[[^\]]*\])*)\]\(\s*(<[^>\n]*>|[^)\s]+)((?:\s+"[^"]*")?)\s*\)/g;

function rewriteLinks(lines, page) {
  const refDef = /^(\s{0,3}\[[^\]]+\]:\s*)(\S+)(.*)$/;
  return mapProse(lines, (text) => {
    text = text.replace(LINK, (all, bang, label, target, title) => {
      const bare = target.startsWith('<') ? target.slice(1, -1) : target;
      const next = rewriteTarget(bare, page, bang === '!');
      return next === bare ? all : `${bang}[${label}](${next}${title})`;
    });
    const d = text.match(refDef);
    if (d) text = d[1] + rewriteTarget(d[2], page, false) + d[3];
    return text;
  });
}

function stripFrontmatter(lines) {
  if (lines[0] === '---') {
    const end = lines.indexOf('---', 1);
    if (end > 0) return lines.slice(end + 1);
  }
  return lines;
}

function collapseBlankLines(lines) {
  const out = [];
  let fence = null;
  let blank = 0;
  for (const line of lines) {
    const f = line.match(FENCE);
    if (f) {
      const marker = f[1][0];
      if (!fence) fence = marker;
      else if (fence === marker) fence = null;
    }
    if (!fence && line.trim() === '') {
      if (++blank > 1) continue;
      out.push('');
      continue;
    }
    blank = 0;
    out.push(line.replace(/[ \t]+$/g, (m) => (fence ? m : '')));
  }
  while (out.length && out[0] === '') out.shift();
  while (out.length && out[out.length - 1] === '') out.pop();
  return out;
}

function plainText(md) {
  return md
    .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/`([^`]*)`/g, '$1')
    .replace(/(\*\*|__|\*|_)(.+?)\1/g, '$2')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ')
    .trim();
}

function describe(lines) {
  let i = lines.findIndex((l) => /^#\s/.test(l));
  i = i === -1 ? 0 : i + 1;
  const para = [];
  for (; i < lines.length; i++) {
    const l = lines[i];
    if (l.trim() === '') {
      if (para.length) break;
      continue;
    }
    if (/^(\s*[|>#]|\s*:::|\s*[-*+]\s|\s*\d+\.\s|\s*!?\[!\[|\s*!\[|\s*```|\s*~~~|\s*<)/.test(l)) {
      if (para.length) break;
      continue;
    }
    para.push(l.trim());
  }
  let text = plainText(para.join(' ')).replace(/^Purpose\.\s+/, '');
  if (text.length > 220) {
    const cut = text.slice(0, 220);
    const sentence = cut.lastIndexOf('. ');
    text = sentence > 80 ? cut.slice(0, sentence + 1) : cut.slice(0, cut.lastIndexOf(' ')) + '…';
  }
  return text.replace(/\s*:$/, '.');
}

function frontmatter(title, description) {
  const q = (s) => JSON.stringify(s); // valid YAML double-quoted scalar
  return `---\ntitle: ${q(title)}\ndescription: ${q(description)}\n---\n`;
}

function renderPage(page) {
  if (!page.exists) {
    const body = [
      `# ${page.title}`,
      '',
      '::: callout warning "Page not written yet"',
      `This page is generated from \`${page.source}\`, which does not exist in the repository yet. It will appear here as soon as the source is written.`,
      ':::',
      '',
      `See the [documentation folder on GitHub](${blobUrl(posix.dirname(page.source), true)}) for what is available today.`,
    ];
    return frontmatter(page.pageTitle ?? page.title, page.description ?? 'This page is not written yet.') + '\n' + body.join('\n') + '\n';
  }

  let lines = stripFrontmatter(readSource(page));
  lines = prepare(lines);
  lines = rewriteLinks(lines, page);
  lines = escapeHtmlInProse(lines);
  lines = collapseBlankLines(lines);

  // The anchors computed for other pages must hold for the final text.
  const expected = pageHeadings(page).map((h) => h.id).join('|');
  const actual = headingsOf(lines).map((h) => h.id).join('|');
  if (expected !== actual) errors.push(`${page.source}: heading ids changed during the transform (a link inside a heading?)`);

  const h1 = lines.find((l) => /^#\s/.test(l));
  const title = page.pageTitle ?? (h1 ? plainHeading(h1.replace(/^#\s+/, '')) : page.title);
  const description = page.description ?? describe(lines);
  if (!description) warnings.push(`${page.source}: no description could be derived`);

  const footer = [
    '',
    '---',
    '',
    `*This page is generated from [\`${page.source}\`](${blobUrl(page.source, false)}). To change it, edit that file in the repository, not this site.*`,
  ];
  return frontmatter(title, description) + '\n' + lines.join('\n') + '\n' + footer.join('\n') + '\n';
}

// ---------------------------------------------------------------- navigation

function buildNavigation() {
  const item = (p) => ({ title: p.title, path: p.route });
  const walk = (items) =>
    items.map((it) => (it.group ? { title: it.group, children: walk(it.items) } : item(pages.find((p) => p.source === it.source))));
  const nav = manifest.sections.map((s) => ({ title: s.title, children: walk(s.items) }));
  nav[0].children.unshift({ title: manifest.landing.title, path: '/' });
  return nav;
}

// ---------------------------------------------------------------- outputs

/** Map of absolute path -> string | Buffer for every generated file. */
function expectedOutputs() {
  const files = new Map();
  for (const p of pages) files.set(p.outFile, renderPage(p));
  for (const a of manifest.assets) {
    const src = join(ROOT, a.from);
    if (existsSync(src)) files.set(join(OUT_ASSETS, a.to), readFileSync(src));
  }
  return files;
}

function staleFiles(expected) {
  const keep = new Set([join(OUT_DOCS, manifest.landing.file)]);
  return walkFiles(OUT_DOCS)
    .map((f) => join(OUT_DOCS, f))
    .filter((f) => f.endsWith('.md') && !expected.has(f) && !keep.has(f));
}

function sameContent(path, content) {
  if (!existsSync(path)) return false;
  const disk = readFileSync(path);
  return Buffer.isBuffer(content) ? disk.equals(content) : disk.toString('utf8') === content;
}

function main() {
  checkCoverage();
  const landing = join(OUT_DOCS, manifest.landing.file);
  if (!existsSync(landing)) errors.push(`${relative(ROOT, landing)} (the hand-written landing page) is missing`);

  const expected = expectedOutputs();
  const nav = buildNavigation();
  const stale = staleFiles(expected);
  const drift = [];

  for (const [path, content] of expected) {
    if (!sameContent(path, content)) drift.push(relative(ROOT, path));
  }
  for (const f of stale) drift.push(`${relative(ROOT, f)} (stale: not produced by the manifest)`);
  for (const cfgPath of CONFIGS) {
    const cfg = JSON.parse(readFileSync(cfgPath, 'utf8'));
    if (JSON.stringify(cfg.navigation) !== JSON.stringify(nav)) drift.push(`${relative(ROOT, cfgPath)} (navigation)`);
  }

  if (CHECK) {
    report(drift);
    return;
  }

  for (const [path, content] of expected) {
    if (sameContent(path, content)) continue;
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, content);
  }
  for (const f of stale) rmSync(f);
  pruneEmptyDirs(OUT_DOCS);
  for (const cfgPath of CONFIGS) {
    const cfg = JSON.parse(readFileSync(cfgPath, 'utf8'));
    cfg.navigation = nav;
    writeFileSync(cfgPath, JSON.stringify(cfg, null, 2) + '\n');
  }
  console.log(`sync: ${expected.size} generated files (${pages.length} pages), ${drift.length} changed.`);
  report([]);
}

function pruneEmptyDirs(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (!statSync(p).isDirectory()) continue;
    pruneEmptyDirs(p);
    if (readdirSync(p).length === 0) rmSync(p, { recursive: true });
  }
}

function report(drift) {
  for (const w of warnings) console.warn(`${STRICT ? 'ERROR' : 'warning'}: ${w}`);
  for (const p of pending) console.warn(`${STRICT ? 'ERROR' : 'pending'}: ${p}`);
  for (const e of errors) console.error(`ERROR: ${e}`);
  if (drift.length) {
    console.error('The committed site sources differ from what the sync produces. Run `npm run sync` in docs-site and commit:');
    for (const d of drift) console.error(`  - ${d}`);
  }
  const fail = errors.length > 0 || drift.length > 0 || (STRICT && (warnings.length > 0 || pending.length > 0));
  if (CHECK && !fail) {
    console.log(`OK: docs-site is in sync with the sources (${pages.length} pages, ${pending.length} pending${STRICT ? ', strict' : ''}).`);
  }
  if (fail) process.exit(1);
}

main();
