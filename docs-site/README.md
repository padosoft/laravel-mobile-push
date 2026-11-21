# Documentation site

Static site for `padosoft/laravel-mobile-push`, built with [docmd](https://docmd.io) (`@docmd/core`) like the sibling package `padosoft/laravel-flow`. It is published on Cloudflare Pages. It is not part of the Composer package (`/docs-site export-ignore`).

## The pages are generated, not copied

The documentation lives once, in the repository: `README.md`, `docs/*.md`, `docs/contracts/*.md`, `benchmarks/RESULTS.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`. `docs/configuration.md` is itself generated from the config schema, and the PHP examples marked `<!-- example: id -->` are executed by a test. The site is **generated from those files**:

```bash
cd docs-site
npm install
npm run sync      # writes docs-site/docs/**, docs-site/assets/{banner,favicon} and the navigation of both docmd configs
```

* **To change a page, edit its source** (`docs/foo.md`), run `npm run sync`, commit the source and the regenerated page together. Never edit `docs-site/docs/**` by hand; the one exception is the landing page `docs-site/docs/index.md`.
* `docs.manifest.json` maps every source to a route, a section and a navigation title, lists the assets copied into the site, and lists what is deliberately not published (`exclude`). A new `docs/*.md` file must be added there (or excluded), otherwise the check fails.
* The sync rewrites links between pages to site routes (anchors included: docmd builds hierarchical heading ids, the sync translates GitHub's), links to other repository files to GitHub URLs on the `main` branch, converts `> [!NOTE]` / `[!WARNING]` blocks to docmd callouts, drops the `<!-- ... -->` comments, turns `<placeholder>` text outside code into entities (docmd escapes raw HTML, see `scripts/check-no-raw-html.mjs`), and derives each page's `title` and `description` from its first heading and paragraph unless the manifest overrides them.
* A source that does not exist yet is **pending**: a stub page keeps the navigation and links working, and the sync reports it without failing.

## Commands

| Command | What it does |
|---|---|
| `npm run dev` | sync, then docmd dev server with live reload |
| `npm run sync` | regenerate the pages, assets and navigation |
| `npm run build` | build the static site into `_site/` (needs the committed pages) |
| `npm run check` | `sync --check` (committed pages equal what the sync produces; no source missing from the manifest), no raw HTML, root/site config consistency, `docmd validate` |
| `npm run verify` | after a build: every internal link, image and `#anchor` of the built HTML resolves |
| `node scripts/sync-docs.mjs --check --strict` | also fails on pending pages/assets, broken links and anchors (CI and the release pass use it) |

Node 22 (`.node-version`; `engines` accepts 20.9+ and 22).

## Two docmd configs

`docs-site/docmd.config.json` is the one Cloudflare Pages uses (it runs from `docs-site/`). The root `docmd.config.json` is the same configuration with repository-root paths (`src: docs-site/docs`), so that `docmd` can also be started from the root, as in laravel-flow. Only `src`, `out`, `favicon` and `theme.customCss` may differ; `scripts/check-config.mjs` (part of `npm run check`) fails when anything else drifts, and the sync writes the same `navigation` into both. Running docmd from the root installs the search dependencies into the root `node_modules`; prefer working inside `docs-site/`.

## Search

The configuration is laravel-flow's: `docmd-search` with the `Xenova/all-MiniLM-L6-v2` embedding model (`@huggingface/transformers`, `onnxruntime-node`). The model is downloaded from Hugging Face during the build. When that download is not possible the build still succeeds and the index falls back to keyword search.

## Cloudflare Pages

Create the project from the GitHub repository `padosoft/laravel-mobile-push` (Workers & Pages, Create, Pages, Connect to Git) with:

| Setting | Value |
|---|---|
| Production branch | `main` |
| Framework preset | None |
| Root directory | `docs-site` |
| Build command | `npm ci && npm run build` |
| Build output directory | `_site` |
| Environment variable | `NODE_VERSION` = `22` |

Then, in the project: **Custom domains, Set up a custom domain**, enter `doc.laravel-mobile-push.padosoft.com` (the `url` in the configs; follow laravel-flow's `doc.laravel-flow.padosoft.com` pattern) and let Cloudflare create the CNAME if the zone is on Cloudflare, otherwise add a CNAME to the `<project>.pages.dev` address at your DNS provider. If the domain differs, change `url` in **both** `docmd.config.json` files (the sitemap and canonical links use it).

No `wrangler` file, `_headers` or `_redirects` is needed (laravel-flow has none).
