# Artwork

| File | What it is |
|---|---|
| `banner.svg` | Editable source of the README / docs-site hero banner (1774×887, 2:1). |
| `favicon.svg` | Small mark derived from the banner: a phone with a notification card and an incoming-push badge. |
| `render.mjs` | Renders an SVG to PNG with headless Chromium (Playwright). |
| `../resources/laravel-mobile-push-banner.png` | The rendered banner (3548×1774, 2× device scale, 256-colour palette, ~0.5 MB). |

## Re-render the banner

```bash
node art/render.mjs
```

This writes `resources/laravel-mobile-push-banner.png` at 1774×887 CSS pixels with `deviceScaleFactor: 2`, then reduces it to a 256-colour palette (median cut refined by k-means, Floyd-Steinberg dithering) with Python Pillow. Without quantisation the PNG is about 3.6 MB; pass `--quantize 0` to keep full colour. If `python3` + Pillow are missing, the full-colour PNG is kept and a warning is printed.

Other options: `--svg`, `--out`, `--width`, `--height`, `--scale`, `--quantize`. Examples:

```bash
# half-size preview, to check legibility at README width
node art/render.mjs --out /tmp/banner-preview.png --scale 0.5 --quantize 0
# favicon checks
node art/render.mjs --svg art/favicon.svg --out /tmp/fav64.png --width 64 --height 64 --scale 1 --quantize 0
node art/render.mjs --svg art/favicon.svg --out /tmp/fav16.png --width 16 --height 16 --scale 1 --quantize 0
```

Playwright and Chromium must be available (`require('playwright')`); the script resolves it with `createRequire`, so a global install works.

## Fonts

The text is real SVG text. It uses fonts installed on the rendering machine:

- **Inter Display** (ExtraBold 800 for the name, Light/Bold for the phone UI) — fallback Inter, Liberation Sans, DejaVu Sans.
- **Inter** (tagline and UI text).
- **JetBrains Mono** (code card, chips, provider labels) with `font-variant-ligatures: none`, otherwise `->` renders as an arrow ligature. Fallback DejaVu Sans Mono.

Check with `fc-list | grep -E "Inter|JetBrains"` before re-rendering; a missing font silently falls back and changes the text widths.

## Palette

| Token | Hex | Use |
|---|---|---|
| ink | `#0B0E22` → `#05060F` | background |
| coral | `#FF5D6C` / `#FF6B78` | origin, APNs beam, title gradient start |
| amber | `#FFA26B` / `#FFB27A` | title gradient end, APNs label |
| cyan | `#46E0CF` | delivery success, Live Activity, FCM beam end |
| violet | `#B98CFF` | middle of the FCM beam |
| text | `#F5F6FF`, `#E2E5F7`, `#C9CDE6`, `#A9AFCF` | text, from strongest to muted |

Measured contrast on the rendered PNG: name ≥ 6.9:1 (coral end of the gradient) and 18:1 (white line), tagline 14:1, chips 11:1.

## No third-party marks

The phones, Dynamic-Island pill, bell and padosoft mark are original generic shapes. Do not add the Apple, Android, Firebase or Laravel logos.

## Alt text for the README

> Laravel Mobile Push banner: one line of Laravel code, MobilePush::to($user)->send(...), sends two beams labelled APNs and FCM to an iPhone-style phone showing a Live Activity and an Android-style phone showing a notification. Tagline: APNs, FCM & Live Activities for Laravel.
