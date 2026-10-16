# Site to Design Token — Extension (slices 1–3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Manifest V3 extension that scans the current tab, shows its colours by role, type, spacing, radius, shadows and contrast in a popup and side panel (light/dark), and exports tokens in five formats behind a Pro flag.

**Architecture:** Pure analysis functions (`src/analyse/`) turn raw style samples into one `Tokens` object; a thin DOM collector (`src/extract/`) produces the samples in the page; pure exporters (`src/export/`) turn `Tokens` into files; Preact UI (`src/ui/`) renders it. Licensing/checkout (slice 4) is out of scope here; Pro is a stored boolean with a dev toggle.

**Tech Stack:** Node 24, TypeScript (strict), Vite 6, Preact 10, Vitest 3 + jsdom, Playwright 1.5x (Chromium, unpacked extension), fflate.

**Spec:** `docs/superpowers/specs/2026-10-16-palette-to-design-token-design.md` (§4–6, §10–12)

## Global Constraints

- Product name in UI and manifest: **Site to Design Token**.
- Manifest V3. Permissions exactly: `activeTab`, `scripting`, `storage`, `sidePanel`. No `host_permissions`. No remote code.
- No emoji anywhere in UI; icons are inline SVG (stroke, `currentColor`).
- All layout CSS uses logical properties (`margin-inline-start`, `padding-inline`, `inset-inline-end`) so RTL works in slice 5.
- Every user-visible string goes through `t(key)`; slice 1–3 ship `en.json` only.
- Theme: `light | dark | system`, default `system`; both themes are one set of CSS variables.
- Free keeps the last **3** saved scans; Pro unlimited.
- Collector caps at **4,000** elements; colour merge threshold **ΔE(OKLab) < 2.5** (OKLab ×100 scale, i.e. 0.025 in raw OKLab units); colours under **0.5%** weight dropped unless used on interactive elements; accent hue differs **> 30°** from primary; chromatic = OKLCH chroma **> 0.04**; spacing base when **≥ 70%** of values are multiples; radius/shadow keep top **4**; contrast AA **4.5** (large text **3.0**), AAA **7.0**; large text = ≥ 24px, or ≥ 18.66px at weight ≥ 700.
- Scan target: < 1.5 s on a typical marketing page.

## Review Focus

1. **Modern colour syntaxes from `getComputedStyle`** (`oklch(...)`, `oklab(...)`, `color(srgb ...)`, `color(display-p3 ...)`, `lab()`, `transparent`) — parse or skip; never produce `NaN`/invalid hex. Test in Task 2.
2. **Semi-transparent text/backgrounds** — alpha-composite over the effective background before contrast; image/gradient behind text → grade `unknown`, not a guess. Test in Task 5.
3. **Sparse pages** (no chromatic colours, one font, no spacing) — roles empty, exporters omit empty sections, UI shows "found little" state; no throw. Tests in Tasks 3 and 9.
4. **Restricted pages** (`chrome://`, Chrome Web Store, PDF viewer) — `executeScript` rejects → `ScanError('restricted')` → "This page can't be scanned". Test in Task 7.
5. **Font families with quotes, commas and fallbacks** (`"Helvetica Neue", Arial, sans-serif`) and **site names with odd characters** — exporters quote correctly per format; file names are slugged. Tests in Tasks 9 and 10.

---

## File structure

```
extension/
  package.json  tsconfig.json  vite.config.ts  vite.extract.config.ts  vitest.config.ts  playwright.config.ts
  public/manifest.json  public/icons/{16,32,48,128}.png
  popup.html  sidepanel.html
  src/model/tokens.ts           the Tokens type (Task 1)
  src/analyse/colour-math.ts    parse, convert, contrast (Task 2)
  src/analyse/colours.ts        cluster + roles (Task 3)
  src/analyse/scales.ts         type, spacing, radius, shadow (Task 4)
  src/analyse/contrast.ts       pairs + fixes (Task 5)
  src/analyse/build.ts          samples → Tokens (Task 6)
  src/extract/collect.ts        DOM walk → Samples (Task 6)
  src/extract/entry.ts          defines globalThis.__sdtScan (Task 6)
  src/scan.ts                   scanTab(tabId) for popup/side panel (Task 7)
  src/store.ts                  settings, saved scans, pro flag (Task 7)
  src/export/{css,tailwind,dtcg,figma,designmd,zip,index}.ts (Tasks 9–10)
  src/ui/i18n.ts  src/i18n/en.json (Task 8)
  src/ui/theme.css  src/ui/theme.ts (Task 8)
  src/ui/components/*.tsx       Header, Tabs, Swatch, Icon… (Task 8)
  src/ui/popup/*.tsx            App + tabs (Tasks 8, 11)
  src/ui/sidepanel/*.tsx        design-system view + Export (Tasks 12, 13)
  test/fixtures/*.html          6 fixture pages (Task 14)
  test/unit/**  test/e2e/**
```

---

### Task 1: Scaffold and the Tokens model

**Files:**
- Create: everything under `extension/` listed for Task 1, `src/model/tokens.ts`, `.gitignore` (`node_modules`, `dist`, `test-results`)
- Test: `test/unit/manifest.test.ts`

**Interfaces:**
- Produces (in `src/model/tokens.ts`, used by every later task):

```ts
export type Role = 'primary' | 'accent' | 'surface' | 'text' | 'border' | 'other';
export interface ColourToken { name: string; hex: string; role: Role; weight: number; interactive: boolean } // name e.g. 'primary', 'primary-2'; weight 0..1 share of all colour weight
export interface TypeToken { name: string; family: string; sizePx: number; weight: number; lineHeightPx: number | null; chars: number } // name: display|h1|h2|h3|h4|body|small|caption|xs
export type Grade = 'AAA' | 'AA' | 'AA-large' | 'fail' | 'unknown';
export interface ContrastPair { fg: string; bg: string; ratio: number | null; large: boolean; uses: number; where: 'body text' | 'headings' | 'links' | 'buttons' | 'other'; grade: Grade; fix?: { fg: string; bg: string; ratio: number } }
export interface Tokens {
  site: string; scannedAt: string;            // origin host, ISO time
  colours: ColourToken[];
  fonts: { name: 'body' | 'heading' | 'mono'; family: string }[];
  type: TypeToken[];
  spacing: { base: 4 | 8 | null; scale: number[] };
  radius: number[];                           // px, 9999 = full
  shadows: string[];                          // CSS box-shadow values
  contrast: ContrastPair[];
}
export const EMPTY_TOKENS: (site: string) => Tokens;
```

- [ ] **Step 1:** `npm init` in `extension/`; add deps `preact fflate`, dev deps `typescript vite @preact/preset-vite vitest jsdom @types/chrome @playwright/test`. Scripts: `build` (= `vite build && vite build -c vite.extract.config.ts`), `test` (= `vitest run`), `e2e` (= `playwright test`).
- [ ] **Step 2: Write the failing test** `test/unit/manifest.test.ts`: reads `public/manifest.json`; asserts `manifest_version === 3`, `name === 'Site to Design Token'`, `permissions` sorted equals `['activeTab','scripting','sidePanel','storage']`, no `host_permissions`, `side_panel.default_path === 'sidepanel.html'`, `action.default_popup === 'popup.html'`, `background.service_worker === 'background.js'`.
- [ ] **Step 3:** `npm test` → FAIL (file missing).
- [ ] **Step 4:** Write `manifest.json`, `tokens.ts`, `vite.config.ts` (inputs `popup.html`, `sidepanel.html`, `src/background.ts` → `background.js`, fixed output names, `emptyOutDir`), `vite.extract.config.ts` (lib mode, `src/extract/entry.ts`, format `iife`, output `dist/extract.js`, `emptyOutDir: false`), stub `background.ts`, empty popup/sidepanel pages rendering the product name.
- [ ] **Step 5:** `npm test` PASS; `npm run build` produces `dist/manifest.json`, `dist/background.js`, `dist/extract.js`, `dist/popup.html`, `dist/sidepanel.html`.
- [ ] **Step 6: Commit** `chore: scaffold MV3 extension and Tokens model`.

### Task 2: Colour maths

**Files:** Create `src/analyse/colour-math.ts`; Test `test/unit/colour-math.test.ts`

**Interfaces — Produces:**
```ts
export interface RGBA { r: number; g: number; b: number; a: number } // r,g,b 0..255 sRGB, a 0..1
export function parseColour(css: string): RGBA | null;   // null for transparent-only, unknown or unparsable
export function toHex(c: RGBA): string;                  // '#RRGGBB' uppercase, alpha dropped
export function composite(top: RGBA, under: RGBA): RGBA; // source-over, result a=1 when under.a=1
export function toOklab(c: RGBA): { L: number; a: number; b: number };
export function toOklch(c: RGBA): { L: number; C: number; h: number };
export function fromOklch(L: number, C: number, h: number): RGBA; // reduces C until in sRGB gamut
export function deltaE(x: RGBA, y: RGBA): number;        // Euclidean OKLab ×100
export function luminance(c: RGBA): number;              // WCAG relative luminance
export function contrastRatio(fg: RGBA, bg: RGBA): number;
```

- [ ] **Step 1: Write failing tests:** `parseColour('rgb(255, 0, 0)')` → hex `#FF0000`; `'rgba(0,0,0,0.5)'` → a 0.5; `'#abc'` → `#AABBCC`; `'transparent'` and `'rgba(0,0,0,0)'` → a 0 (not null); `'oklch(0.628 0.2577 29.23)'` → hex within 1 unit per channel of `#FF0000`; `'color(srgb 1 0 0)'` → `#FF0000`; `'color(display-p3 1 0 0)'` → in-gamut sRGB with r 255; `'lab(50% 40 30)'` → non-null valid hex; `'banana'` → null; no result anywhere ever contains `NaN`. `contrastRatio(black, white)` = 21 (±0.01); `#777777` on white = 4.48 (±0.01); `composite(rgba(0,0,0,.5), white)` → `#808080` (±1). `deltaE(#FF0000, #FE0000) < 2.5`; `deltaE(#FF0000, #00FF00) > 30`. `fromOklch(toOklch(c))` round-trips `#1F5E4B` exactly.
- [ ] **Step 2:** run `npx vitest run test/unit/colour-math.test.ts` → FAIL.
- [ ] **Step 3:** Implement. Use the published OKLab matrices (Björn Ottosson); CIE Lab via D50 XYZ with Bradford to D65; display-p3 via its linear matrix to XYZ then sRGB, clamp by `fromOklch` chroma reduction.
- [ ] **Step 4:** tests PASS.
- [ ] **Step 5: Commit** `feat(analyse): colour parsing, OKLab and WCAG contrast`.

### Task 3: Colour clustering and roles

**Files:** Create `src/analyse/colours.ts`; Test `test/unit/colours.test.ts`

**Interfaces:**
- Consumes: Task 2 functions; `ColourToken`, `Role`.
- Produces:
```ts
export type ColourKind = 'text' | 'bg' | 'border' | 'fill';
export interface ColourSample { css: string; kind: ColourKind; weight: number; interactive: boolean; nav: boolean } // weight = area px² for bg, characters for text, perimeter px for border, area for fill
export function analyseColours(samples: ColourSample[]): ColourToken[];
```

- [ ] **Step 1: Write failing tests:**
  - two bg samples `#FFFDF8` (weight 900k) and `#FFFDF9` (weight 10k) merge into one token, hex of the heavier.
  - Roles on a synthetic page: bg `#FFFDF8` 900k + `#F3EEE4` 200k → both `surface` (`surface`, `surface-2`, light→dark); text `#1B1A17` 5000 chars + `#5C574E` 1500 → `text`, `text-2`; `#1F5E4B` as bg on 6 interactive buttons + nav → `primary`; `#E0763A` interactive, hue > 30° from primary → `accent`; `#E4DDD0` border → `border`; a chromatic colour with hue within 30° of primary that is not most-used → `primary-2`.
  - A colour with weight < 0.5% and `interactive: false` is dropped; same weight with `interactive: true` kept.
  - Samples with alpha 0 are ignored; semi-transparent bg `rgba(0,0,0,.04)` is composited over white before clustering.
  - Sparse page (only white bg and black text) → no `primary`/`accent` tokens, no throw; `analyseColours([])` → `[]`.
  - Every returned `weight` sums to ≤ 1 and names are unique.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** Implement per spec §6.1 steps 2–3 with the Global Constraints thresholds. Greedy clustering: sort by weight desc, attach each sample to the first cluster with ΔE < 2.5. Role precedence when a colour qualifies twice: primary > accent > text > surface > border > other.
- [ ] **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat(analyse): colour clustering and role assignment`.

### Task 4: Type, spacing, radius, shadow scales

**Files:** Create `src/analyse/scales.ts`; Test `test/unit/scales.test.ts`

**Interfaces — Produces:**
```ts
export interface TextSample { family: string; sizePx: number; weight: number; lineHeightPx: number | null; chars: number; heading: boolean; mono: boolean }
export function analyseType(s: TextSample[]): { fonts: Tokens['fonts']; type: TypeToken[] };
export function analyseSpacing(values: number[]): Tokens['spacing'];
export function analyseRadius(values: number[]): number[];
export function analyseShadows(values: string[]): string[];
```

- [ ] **Step 1: Write failing tests:**
  - Type: samples 16px (8000 chars), 48px heading, 32px heading, 24px heading, 13px (900 chars), 15.7px (merges with 16 within ±0.5) → names `display`(48), `h1`(32), `h2`(24), `body`(16), `small`(13). `display` only when ≥ 40px, else the largest is `h1`. Sizes below body: `small`, `caption`, `xs` in order.
  - Fonts: body family = family of `body`; `heading` only if a heading token's family differs; `mono` if any mono sample. Family strings keep their CSS form (`"Helvetica Neue", Arial, sans-serif`) verbatim.
  - Spacing: `[4,8,8,16,24,32,12,48,0,0,1]` → base 4 (zeros ignored; 1px treated as a non-multiple), scale = distinct values with ≥ 2 uses ≤ 128 sorted, max 12; all multiples of 8 at ≥ 70% → base 8; `[]` → `{base:null, scale:[]}`.
  - Radius `[4,4,10,10,10,9999,50%→passed as 9999,2]` → top 4 by frequency, sorted asc, values ≥ 999 normalised to 9999.
  - Shadows: `none` ignored; top 4 by frequency, original strings.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat(analyse): type, spacing, radius and shadow scales`.

### Task 5: Contrast pairs and fixes

**Files:** Create `src/analyse/contrast.ts`; Test `test/unit/contrast.test.ts`

**Interfaces — Produces:**
```ts
export interface PairSample { fg: string; bg: string | null; chars: number; sizePx: number; weight: number; where: ContrastPair['where'] } // bg null = image/gradient behind
export function grade(ratio: number | null, large: boolean): Grade;
export function analyseContrast(s: PairSample[]): ContrastPair[];   // grouped by fg+bg hex+large, sorted by uses desc, max 12
export function suggestFix(fg: RGBA, bg: RGBA, large: boolean): { fg: string; bg: string; ratio: number } | null;
```

- [ ] **Step 1: Write failing tests:** `grade(7.6,false)`=`AAA`; `grade(5,false)`=`AA`; `grade(3.2,true)`=`AA-large`; `grade(3.2,false)`=`fail`; `grade(null,…)`=`unknown`. Large = ≥ 24px or ≥ 18.66px at weight ≥ 700. White on `#E0763A` → `fail` with a `fix` whose ratio ≥ 4.5, which changed only the chromatic side (the bg), hue within 2° and in gamut. Black on white → no `fix`. `fg` `rgba(0,0,0,.6)` on white is composited before rating (ratio ≈ 5.7). `bg: null` → `ratio: null`, `grade: 'unknown'`, no fix. Same pair from 3 samples → one pair with `uses: 3`.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** `suggestFix`: pick the side with the higher OKLCH chroma (ties → bg); step its L by 0.01 in the direction that increases contrast (darker if the other side is lighter) up to 100 steps; stop at the first ratio ≥ target (4.5, or 3.0 when large); `fromOklch` keeps it in gamut. Return null if no step passes.
- [ ] **Step 4:** PASS. **Step 5: Commit** `feat(analyse): contrast grading and fixes`.

### Task 6: Collector and `buildTokens`

**Files:** Create `src/extract/collect.ts`, `src/extract/entry.ts`, `src/analyse/build.ts`; Test `test/unit/collect.test.ts`, `test/unit/build.test.ts`

**Interfaces:**
- Consumes: Tasks 3–5 sample types and analysers.
- Produces:
```ts
export interface Samples { site: string; colours: ColourSample[]; text: TextSample[]; spacing: number[]; radius: number[]; shadows: string[]; pairs: PairSample[]; truncated: boolean }
export interface Measure { rect(el: Element): { width: number; height: number }; style(el: Element): CSSStyleDeclaration }
export function collect(root: Document, measure?: Measure, limit?: number): Samples; // default measure uses getBoundingClientRect/getComputedStyle; limit 4000
export function buildTokens(s: Samples, now?: Date): Tokens;
// entry.ts: globalThis.__sdtScan = (): Tokens => buildTokens(collect(document))
```

- [ ] **Step 1: Write failing tests** (jsdom; a stub `Measure` returning fixed rects and computed styles from a map, since jsdom has no layout):
  - elements with `display:none`, `visibility:hidden`, `opacity:0` or a 0×0 rect produce no samples.
  - a `<button>` and `<a>` produce `interactive: true` colour samples; elements inside `<nav>`/`<header>` get `nav: true`.
  - effective background for a text element = first ancestor whose bg alpha > 0 (composited down to an opaque one); an ancestor with `background-image` other than `none` before an opaque one → pair `bg: null`.
  - `where`: `button`/`[role=button]` → `buttons`, `a` → `links`, `h1–h6` → `headings`, others with text → `body text`.
  - 5,000 elements with `limit` 4000 → 4,000 visited, `truncated: true`.
  - open shadow roots are walked.
  - `buildTokens` on a sparse `Samples` (white bg, black text, nothing else) returns a valid `Tokens` with empty `spacing.scale`, `radius`, `shadows`, no throw.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS; `npm run build` emits `dist/extract.js` with no `import` statements.
- [ ] **Step 5: Commit** `feat(extract): DOM collector and Tokens builder`.

### Task 7: Scanning a tab and storage

**Files:** Create `src/scan.ts`, `src/store.ts`; Modify `src/background.ts`; Test `test/unit/scan.test.ts`, `test/unit/store.test.ts` (with a fake `chrome` object in `test/unit/fake-chrome.ts`)

**Interfaces — Produces:**
```ts
export class ScanError extends Error { constructor(public reason: 'restricted' | 'no-tab' | 'failed') }
export function scanTab(tabId: number): Promise<Tokens>; // executeScript files:['extract.js'] then func: () => globalThis.__sdtScan()
export interface Settings { theme: 'light' | 'dark' | 'system'; lang: string; pro: boolean }
export function getSettings(): Promise<Settings>;          // defaults {theme:'system', lang: '' (= auto), pro:false}
export function setSettings(p: Partial<Settings>): Promise<void>;
export function saveScan(t: Tokens): Promise<void>;        // keyed by t.site; newest first; free keeps 3
export function listScans(): Promise<Tokens[]>;
export function onSettingsChange(cb: (s: Settings) => void): () => void;
```

- [ ] **Step 1: Write failing tests:** `scanTab` when `executeScript` rejects with "Cannot access a chrome:// URL" or "The extensions gallery cannot be scripted" → `ScanError('restricted')`; other rejections → `'failed'`; resolves with the Tokens from the second call. `saveScan` ×4 different sites with `pro:false` → `listScans()` length 3, oldest gone; same site twice → one entry, moved to front; with `pro:true` → 4 kept. Settings defaults as above; `setSettings({theme:'dark'})` persists and fires `onSettingsChange`.
- [ ] **Step 2:** FAIL. **Step 3:** implement over `chrome.storage.local`. `background.ts`: `chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: false })`; nothing else yet. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: scan the active tab and store scans and settings`.

### Task 8: UI shell — theme, i18n, popup Colours tab

**Files:** Create `src/ui/theme.css`, `src/ui/theme.ts`, `src/ui/i18n.ts`, `src/i18n/en.json`, `src/ui/components/{Icon,Header,Tabs,Swatch,EmptyState}.tsx`, `src/ui/popup/{main,App,ColoursTab}.tsx`; Test `test/unit/i18n.test.ts`, `test/unit/ui-popup.test.tsx`

**Interfaces:**
- Consumes: `scanTab`, `getSettings`, `saveScan`, `Tokens`.
- Produces:
```ts
export function t(key: string, vars?: Record<string, string | number>): string; // falls back to en, then the key
export function setLang(lang: string): void;   // sets document dir ('rtl' for 'ar') and lang
export function resolveLang(pref: string, browser: string): string; // one of en,zh-CN,hi,es,ar,fr,bn; else 'en'
export function applyTheme(pref: Settings['theme']): void; // sets data-theme on <html>; 'system' follows prefers-color-scheme live
```

- [ ] **Step 1: Write failing tests:** `t('popup.copyHexList')` = `"Copy hex list"`; `t('header.counts', {colours: 14, fonts: 3})` = `"14 colours · 3 fonts"`; unknown key returns the key; `resolveLang('', 'es-MX')` = `es`, `('', 'de')` = `en`, `('ar','en')` = `ar`; `setLang('ar')` sets `dir="rtl"`. Render `<App>` with a mocked scan of the mockup data (`brewline.co`): headings Primary/Accent/Surface/Text present in that order, swatch hex text `#1F5E4B`, clicking a swatch calls `navigator.clipboard.writeText('#1F5E4B')` and shows "Copied"; "Export tokens" shows a PRO badge when `pro:false`. Scan rejecting with `restricted` renders "This page can't be scanned". Tokens with no chromatic colours renders the "found little" note, not an empty crash.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** Implement to match mockup A (artifact above): 400×600, Geist UI face bundled locally (woff2 in `public/fonts/`, no Google Fonts at runtime) with system fallbacks, tokens from the mockup (`--bg #FBFBFA`, `--ink #16181D`, `--muted #5D6270`, `--line #E3E4E8`, `--accent #3B4BD8`; dark: `--bg #111318`, `--ink #ECEDF1`, `--muted #9DA2B0`, `--line #22252D`, `--accent #7C8BFF`). Settings menu in the header holds Theme (Light/Dark/System) and Language (English only for now). Tabs: Colours · Type · Spacing · Contrast; only Colours filled here.
- [ ] **Step 4:** PASS; `npm run build`, load `dist/` unpacked in Chrome, open on any site — popup shows colours (manual check, note the site in the commit message).
- [ ] **Step 5: Commit** `feat(ui): popup shell, themes, i18n and Colours tab`.

### Task 9: Exporters — CSS, Tailwind, DTCG JSON, Figma

**Files:** Create `src/export/{css,tailwind,dtcg,figma,index}.ts`; Test `test/unit/export/*.test.ts` + golden files `test/unit/export/golden/brewline.{css,tw4.css,tw3.js,tokens.json,figma.json}`; shared fixture `test/unit/export/brewline.tokens.ts` (the mockup data)

**Interfaces — Produces:**
```ts
export type Part = 'colours' | 'typography' | 'spacing' | 'radius' | 'shadows';
export interface ExportOptions { parts: Part[]; tailwind?: 3 | 4 }
export type Format = 'css' | 'tailwind' | 'json' | 'figma' | 'md' | 'all';
export function exportFile(tokens: Tokens, format: Exclude<Format, 'all'>, o: ExportOptions): { filename: string; mime: string; text: string };
export function slug(site: string): string; // 'brewline.co' → 'brewline-co'; strips anything not [a-z0-9-]
```
Naming (all formats): colours `color-<token.name>`; fonts `font-<body|heading|mono>`; type `text-<name>` with size, line-height, weight; spacing `space-<i>` (1-based, scale order); radius `radius-sm|md|lg|xl` in ascending order, 9999 → `radius-full`; shadows `shadow-sm|md|lg|xl`.

- [ ] **Step 1: Write failing tests:** each format on `brewline.tokens` equals its golden file; filenames `tokens.css`, `tailwind.css` (v4) / `tailwind.config.js` (v3), `tokens.json`, `figma-variables.json`. `parts: ['colours']` → no font/space/radius/shadow output in any format. A Tokens with empty `shadows` emits no shadow section/comment. Font family `"Helvetica Neue", Arial, sans-serif` emits valid CSS (unchanged), a valid JS array `["Helvetica Neue", "Arial", "sans-serif"]` in v3 config, and valid JSON (parse round-trip) in DTCG/Figma. DTCG: every leaf has `$type` (`color`, `fontFamily`, `dimension` with `{value, unit:'px'}`, `fontWeight`, `shadow`); a shadow is parsed into DTCG shadow objects `{color, offsetX, offsetY, blur, spread}` (an array for multi-layer), `inset` shadows and unparseable values are omitted from JSON/Figma but kept in CSS/Tailwind. Figma: DTCG grouped as `color/`, `space/`, `radius/` (numbers as `$type: 'number'`), one mode `Light`.
- [ ] **Step 2:** FAIL. **Step 3:** implement; write goldens by hand from the naming rules, not from the output. **Step 4:** PASS.
- [ ] **Step 5:** Manual check: import `figma-variables.json` into Figma (Variables → Import). If Figma rejects the shape, adjust to what it accepts and update the golden; record the result in the commit message.
- [ ] **Step 6: Commit** `feat(export): CSS, Tailwind, DTCG and Figma exporters`.

### Task 10: DESIGN.md and zip

**Files:** Create `src/export/{designmd,zip}.ts`; Modify `src/export/index.ts`; Test `test/unit/export/designmd.test.ts`, `zip.test.ts`, golden `brewline.DESIGN.md`

**Interfaces — Produces:** `exportFile(t, 'md', o)` → `DESIGN.md`; `exportAll(t, o): Promise<{ filename: string; mime: 'application/zip'; bytes: Uint8Array }>` → `<slug>-tokens.zip`.

- [ ] **Step 1: Write failing tests:** golden equality. Required structure, in order: `# Design system — <site>`; a 2-line instruction paragraph ("Use only these tokens for UI. Do not invent new colours, fonts or spacing."); `## Colour roles` table `| Role | Token | Hex | Use for |` with use-for text per role (primary: "links, primary buttons, nav", accent: "highlights, badges, secondary CTAs", surface: "page and card backgrounds", text: "body copy and headings", border: "dividers and outlines", other: "—"); `## Typography` (fonts then scale rows `name: family weight, size/line-height`); `## Spacing`; `## Radius`; `## Shadows`; `## Contrast rules` listing every `fail` pair as "Do not put <fg> text on <bg> (<ratio>:1). Use <fix>." and `unknown` pairs as "Check manually". Empty sections omitted. Zip contains exactly the five files (Tailwind v4), unzips with fflate, names match `exportFile` names; a site `My Site (beta)` → `my-site-beta-tokens.zip`.
- [ ] **Step 2:** FAIL. **Step 3:** implement (fflate `zipSync`). **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat(export): DESIGN.md for AI and zip bundle`.

### Task 11: Popup Type, Spacing and Contrast tabs

**Files:** Create `src/ui/popup/{TypeTab,SpacingTab,ContrastTab}.tsx`; Test `test/unit/ui-tabs.test.tsx`

**Interfaces:** Consumes `Tokens`, `t`, `Settings.pro`.

- [ ] **Step 1: Write failing tests:** Type tab lists each `TypeToken` as a live sample in its family/size/weight with `family weight · size/line-height` meta. Spacing tab shows bars per scale value and the base. Contrast tab: summary counts (pass = AAA+AA+AA-large, fail, unknown) matching the pairs; each row shows `fg on bg`, `where · N uses`, ratio and grade text (grade conveyed by text, not colour alone); a `fail` pair with `pro:false` shows the fix card locked ("PRO" + unlock button, colours hidden); with `pro:true` shows before/after and the fixed ratio.
- [ ] **Step 2:** FAIL. **Step 3:** implement to mockups A and C. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat(ui): Type, Spacing and Contrast tabs`.

### Task 12: Side panel — design-system view

**Files:** Create `sidepanel.html` body, `src/ui/sidepanel/{main,App,SystemView}.tsx`; Modify `src/ui/popup/App.tsx` (an "Open full view" button calling `chrome.sidePanel.open({ tabId })`); Test `test/unit/ui-sidepanel.test.tsx`

**Interfaces:** Consumes `listScans`, `scanTab`, `Tokens`, shared components.

- [ ] **Step 1: Write failing tests:** side panel shows the latest saved scan for the active tab's host (from `listScans`) without rescanning; "Re-scan" calls `scanTab`; sections Colours (proportional strip + legend), Typography, Spacing, Radius, Shadows in that order; empty sections hidden; badge "View only" when `pro:false`; footer button "Export design system" navigates to the Export screen (Task 13). Switching theme in the popup updates an open side panel (via `onSettingsChange`).
- [ ] **Step 2:** FAIL. **Step 3:** implement to mockup B. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat(ui): side panel design-system view`.

### Task 13: Export screen and Pro gate

**Files:** Create `src/ui/sidepanel/ExportScreen.tsx`, `src/ui/components/UnlockSheet.tsx`, `src/ui/download.ts`; Test `test/unit/ui-export.test.tsx`

**Interfaces:**
- Consumes: `exportFile`, `exportAll`, `Settings.pro`.
- Produces: `download(filename: string, data: string | Uint8Array, mime: string): void` (object URL + `<a download>`, revoked after click).

- [ ] **Step 1: Write failing tests:** format grid order DESIGN.md, CSS, Tailwind, Figma, JSON, All (DESIGN.md selected first); Tailwind shows a v4/v3 toggle; Include chips default all on and change the preview text; preview shows exactly `exportFile(...).text`; Copy writes it to the clipboard; Download calls `download` with the right filename/mime. With `pro:false`, Copy/Download/zip open `UnlockSheet` instead (preview still visible); the sheet's "Pay with PayPal" is disabled with "Coming soon" until slice 4, and the key field is present but inert. In development builds (`import.meta.env.DEV`), the settings menu has "Dev: toggle Pro".
- [ ] **Step 2:** FAIL. **Step 3:** implement to mockups D and E. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat(ui): export screen with Pro gate`.

### Task 14: Fixture pages and end-to-end tests

**Files:** Create `test/fixtures/{light-marketing,dark-app,gradient-hero,tailwind,arabic-rtl,near-empty}.html`, `test/fixtures/expected.json`, `test/e2e/extension.spec.ts`, `playwright.config.ts`

**Interfaces:** Consumes the built `dist/`.

- [ ] **Step 1: Write failing tests:** Playwright launches Chromium persistent context with `--disable-extensions-except=dist --load-extension=dist`, serves fixtures over a local HTTP server, gets the extension id from the service worker URL. For each fixture, run `__sdtScan` via the service worker's `chrome.scripting` on that tab and assert roles in `expected.json` (e.g. light-marketing primary `#1F5E4B`, accent `#E0763A`; dark-app surface darkest-first; gradient-hero headline pair `unknown`; near-empty has no primary and does not throw). Open `popup.html` as a page for the fixture tab: Colours tab renders; theme Dark persists after reload; export of each format with dev Pro produces a non-empty download; scanning `chrome://extensions` shows "This page can't be scanned". Scan of `light-marketing` completes in < 1500 ms.
- [ ] **Step 2:** `npm run build && npm run e2e` → FAIL until fixtures exist.
- [ ] **Step 3:** write fixtures (static HTML, inline CSS, no network) and expectations derived from their CSS by hand.
- [ ] **Step 4:** PASS.
- [ ] **Step 5: Commit** `test: fixture pages and end-to-end extension tests`; push `main`.

---

## Not in this plan (planned later)

- Slice 4 — server (`server/`), checkout, license activation: after the tax decision (spec §3).
- Slice 5 — six more languages + RTL polish (i18n plumbing and logical CSS are already in place).
- Slice 6 — store package, listing, privacy policy.
