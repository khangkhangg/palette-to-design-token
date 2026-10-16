/** Pure colour maths: CSS colour parsing, OKLab/OKLCH, WCAG contrast. No DOM. */

export interface RGBA { r: number; g: number; b: number; a: number } // r,g,b 0..255 sRGB, a 0..1

type Vec3 = [number, number, number];

const clamp = (v: number, lo: number, hi: number) => (v < lo ? lo : v > hi ? hi : v);
const fin = (v: number) => (Number.isFinite(v) ? v : 0);

// ---- transfer functions ---------------------------------------------------

function srgbToLinear(v: number): number {
  const s = Math.sign(v);
  const x = Math.abs(v);
  return s * (x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4));
}

function linearToSrgb(v: number): number {
  const s = Math.sign(v);
  const x = Math.abs(v);
  return s * (x <= 0.0031308 ? x * 12.92 : 1.055 * Math.pow(x, 1 / 2.4) - 0.055);
}

// ---- OKLab (Bjorn Ottosson) on unbounded linear sRGB ------------------------

function linToOklab(r: number, g: number, b: number): Vec3 {
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  return [
    0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
    1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
    0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
  ];
}

function oklabToLin(L: number, a: number, b: number): Vec3 {
  const l = Math.pow(L + 0.3963377774 * a + 0.2158037573 * b, 3);
  const m = Math.pow(L - 0.1055613458 * a - 0.0638541728 * b, 3);
  const s = Math.pow(L - 0.0894841775 * a - 1.291485548 * b, 3);
  return [
    4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  ];
}

const GAMUT_EPS = 1e-4;
const inLinGamut = (v: Vec3) => v.every((x) => x >= -GAMUT_EPS && x <= 1 + GAMUT_EPS);

/** OKLCH to sRGB, reducing chroma until the colour fits the sRGB gamut. */
function oklchToRgb(L: number, C: number, h: number): RGBA {
  L = clamp(fin(L), 0, 1);
  C = Math.max(0, fin(C));
  const hr = (fin(h) * Math.PI) / 180;
  const at = (c: number) => oklabToLin(L, c * Math.cos(hr), c * Math.sin(hr));
  let lin = at(C);
  if (!inLinGamut(lin)) {
    let lo = 0;
    let hi = C;
    for (let i = 0; i < 24; i++) {
      const mid = (lo + hi) / 2;
      if (inLinGamut(at(mid))) lo = mid;
      else hi = mid;
    }
    lin = at(lo);
  }
  const ch = (v: number) => Math.round(clamp(linearToSrgb(clamp(v, 0, 1)) * 255, 0, 255));
  return { r: ch(lin[0]), g: ch(lin[1]), b: ch(lin[2]), a: 1 };
}

function oklabToRgb(L: number, a: number, b: number): RGBA {
  return oklchToRgb(L, Math.hypot(a, b), (Math.atan2(b, a) * 180) / Math.PI);
}

// ---- XYZ helpers ---------------------------------------------------------------

const mul = (m: number[], v: Vec3): Vec3 => [
  m[0] * v[0] + m[1] * v[1] + m[2] * v[2],
  m[3] * v[0] + m[4] * v[1] + m[5] * v[2],
  m[6] * v[0] + m[7] * v[1] + m[8] * v[2],
];

const XYZ_D65_TO_LIN_SRGB = [3.2404542, -1.5371385, -0.4985314, -0.969266, 1.8760108, 0.041556, 0.0556434, -0.2040259, 1.0572252];
const LIN_P3_TO_XYZ_D65 = [0.4865709, 0.2656677, 0.1982173, 0.2289746, 0.6917385, 0.0792869, 0, 0.0451134, 1.0439444];
const BRADFORD_D50_TO_D65 = [0.9555766, -0.0230393, 0.0631636, -0.0282895, 1.0099416, 0.0210077, 0.0122982, -0.020483, 1.3299098];
const D50_WHITE: Vec3 = [0.3457 / 0.3585, 1, (1 - 0.3457 - 0.3585) / 0.3585];

function xyzD65ToOklab(xyz: Vec3): Vec3 {
  const [r, g, b] = mul(XYZ_D65_TO_LIN_SRGB, xyz);
  return linToOklab(r, g, b);
}

function cieLabToOklab(L: number, a: number, b: number): Vec3 {
  const e = 216 / 24389;
  const k = 24389 / 27;
  const fy = (L + 16) / 116;
  const fx = a / 500 + fy;
  const fz = fy - b / 200;
  const f3 = (f: number) => (f ** 3 > e ? f ** 3 : (116 * f - 16) / k);
  const yr = L > k * e ? fy ** 3 : L / k;
  const xyz50: Vec3 = [f3(fx) * D50_WHITE[0], yr * D50_WHITE[1], f3(fz) * D50_WHITE[2]];
  return xyzD65ToOklab(mul(BRADFORD_D50_TO_D65, xyz50));
}

// ---- public: conversions --------------------------------------------------------

export function toHex(c: RGBA): string {
  const h = (v: number) => Math.round(clamp(fin(v), 0, 255)).toString(16).padStart(2, '0');
  return ('#' + h(c.r) + h(c.g) + h(c.b)).toUpperCase();
}

export function composite(top: RGBA, under: RGBA): RGBA {
  const a = top.a + under.a * (1 - top.a);
  if (a <= 0) return { r: 0, g: 0, b: 0, a: 0 };
  const mix = (t: number, u: number) => (t * top.a + u * under.a * (1 - top.a)) / a;
  return { r: mix(top.r, under.r), g: mix(top.g, under.g), b: mix(top.b, under.b), a };
}

export function toOklab(c: RGBA): { L: number; a: number; b: number } {
  const [L, a, b] = linToOklab(srgbToLinear(c.r / 255), srgbToLinear(c.g / 255), srgbToLinear(c.b / 255));
  return { L, a, b };
}

export function toOklch(c: RGBA): { L: number; C: number; h: number } {
  const { L, a, b } = toOklab(c);
  const C = Math.hypot(a, b);
  let h = C < 1e-6 ? 0 : (Math.atan2(b, a) * 180) / Math.PI;
  if (h < 0) h += 360;
  return { L, C, h };
}

export function fromOklch(L: number, C: number, h: number): RGBA {
  return oklchToRgb(L, C, h);
}

export function deltaE(x: RGBA, y: RGBA): number {
  const p = toOklab(x);
  const q = toOklab(y);
  return Math.hypot(p.L - q.L, p.a - q.a, p.b - q.b) * 100;
}

export function luminance(c: RGBA): number {
  return 0.2126 * srgbToLinear(c.r / 255) + 0.7152 * srgbToLinear(c.g / 255) + 0.0722 * srgbToLinear(c.b / 255);
}

export function contrastRatio(fg: RGBA, bg: RGBA): number {
  const a = luminance(fg);
  const b = luminance(bg);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

// ---- parsing ----------------------------------------------------------------------

const rgbOut = (r: number, g: number, b: number, a: number): RGBA => ({
  r: Math.round(clamp(fin(r), 0, 255)),
  g: Math.round(clamp(fin(g), 0, 255)),
  b: Math.round(clamp(fin(b), 0, 255)),
  a: clamp(fin(a), 0, 1),
});

const withAlpha = (c: RGBA, a: number): RGBA => ({ ...c, a: clamp(fin(a), 0, 1) });

/** One CSS component: number, percentage (scaled to `pctRef`), angle (to degrees) or `none` (0). */
function comp(tok: string, pctRef: number, hue = false): number | null {
  const t = tok.toLowerCase();
  if (t === 'none') return 0;
  const m = /^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(%|deg|grad|rad|turn)?$/.exec(t);
  if (!m) return null;
  const n = parseFloat(m[1]);
  const u = m[2];
  if (u === '%') return (n / 100) * pctRef;
  if (hue) {
    if (u === 'grad') return n * 0.9;
    if (u === 'rad') return (n * 180) / Math.PI;
    if (u === 'turn') return n * 360;
    return n;
  }
  return u ? null : n;
}

const NAMED: Record<string, string> = {
  black: '#000000', white: '#ffffff', red: '#ff0000', green: '#008000', blue: '#0000ff',
  yellow: '#ffff00', cyan: '#00ffff', aqua: '#00ffff', magenta: '#ff00ff', fuchsia: '#ff00ff',
  gray: '#808080', grey: '#808080', silver: '#c0c0c0', maroon: '#800000', olive: '#808000',
  lime: '#00ff00', teal: '#008080', navy: '#000080', purple: '#800080', orange: '#ffa500',
};

function parseHex(s: string): RGBA | null {
  const m = /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.exec(s);
  if (!m) return null;
  let h = m[1];
  if (h.length <= 4) h = h.split('').map((c) => c + c).join('');
  const n = (i: number) => parseInt(h.slice(i, i + 2), 16);
  return { r: n(0), g: n(2), b: n(4), a: h.length === 8 ? n(6) / 255 : 1 };
}

function hslToRgb(h: number, s: number, l: number): RGBA {
  s = clamp(s, 0, 1);
  l = clamp(l, 0, 1);
  const k = (n: number) => (n + h / 30) % 12;
  const a = s * Math.min(l, 1 - l);
  const f = (n: number) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
  return rgbOut(f(0) * 255, f(8) * 255, f(4) * 255, 1);
}

/** Returns null only for unknown/unparsable input; `transparent` is a=0. */
export function parseColour(css: string): RGBA | null {
  if (typeof css !== 'string') return null;
  const s = css.trim();
  if (!s) return null;
  const lower = s.toLowerCase();
  if (lower === 'transparent') return { r: 0, g: 0, b: 0, a: 0 };
  if (s[0] === '#') return parseHex(s);
  if (NAMED[lower]) return parseHex(NAMED[lower]);

  const fm = /^([a-z-]+)\(\s*([^)]*?)\s*\)$/i.exec(s);
  if (!fm) return null;
  const fn = fm[1].toLowerCase();
  const [body, alphaTok, ...extra] = fm[2].split('/').map((p) => p.trim());
  if (extra.length) return null;
  const toks = body.split(/[\s,]+/).filter(Boolean);
  // Legacy comma syntax puts alpha as a 4th token (5th for color()).
  let aTok = alphaTok;
  const withoutAlpha = fn === 'color' ? 4 : 3;
  if (aTok === undefined && toks.length === withoutAlpha + 1) aTok = toks.pop();
  let alpha = 1;
  if (aTok !== undefined) {
    const v = comp(aTok, 1);
    if (v === null) return null;
    alpha = v;
  }

  const take = (n: number, refs: number[], hueIdx = -1): number[] | null => {
    if (toks.length !== n) return null;
    const out: number[] = [];
    for (let i = 0; i < n; i++) {
      const v = comp(toks[i], refs[i], i === hueIdx);
      if (v === null) return null;
      out.push(v);
    }
    return out;
  };

  switch (fn) {
    case 'rgb':
    case 'rgba': {
      const v = take(3, [255, 255, 255]);
      return v && rgbOut(v[0], v[1], v[2], alpha);
    }
    case 'hsl':
    case 'hsla': {
      const v = take(3, [360, 1, 1], 0);
      if (!v) return null;
      // Bare numbers for s/l mean percentages in hsl().
      const sv = /%|none/i.test(toks[1]) ? v[1] : v[1] / 100;
      const lv = /%|none/i.test(toks[2]) ? v[2] : v[2] / 100;
      return withAlpha(hslToRgb(((v[0] % 360) + 360) % 360, sv, lv), alpha);
    }
    case 'oklch': {
      const v = take(3, [1, 0.4, 360], 2);
      return v && withAlpha(oklchToRgb(v[0], v[1], v[2]), alpha);
    }
    case 'oklab': {
      const v = take(3, [1, 0.4, 0.4]);
      return v && withAlpha(oklabToRgb(v[0], v[1], v[2]), alpha);
    }
    case 'lab': {
      const v = take(3, [100, 125, 125]);
      if (!v) return null;
      const [L, a, b] = cieLabToOklab(clamp(v[0], 0, 100), v[1], v[2]);
      return withAlpha(oklabToRgb(L, a, b), alpha);
    }
    case 'lch': {
      const v = take(3, [100, 150, 360], 2);
      if (!v) return null;
      const hr = (v[2] * Math.PI) / 180;
      const c = Math.max(0, v[1]);
      const [L, a, b] = cieLabToOklab(clamp(v[0], 0, 100), c * Math.cos(hr), c * Math.sin(hr));
      return withAlpha(oklabToRgb(L, a, b), alpha);
    }
    case 'color': {
      const space = (toks.shift() ?? '').toLowerCase();
      const v = take(3, [1, 1, 1]);
      if (!v) return null;
      if (space === 'srgb') return rgbOut(v[0] * 255, v[1] * 255, v[2] * 255, alpha);
      if (space === 'srgb-linear') {
        return rgbOut(linearToSrgb(v[0]) * 255, linearToSrgb(v[1]) * 255, linearToSrgb(v[2]) * 255, alpha);
      }
      if (space === 'display-p3') {
        const xyz = mul(LIN_P3_TO_XYZ_D65, [srgbToLinear(v[0]), srgbToLinear(v[1]), srgbToLinear(v[2])]);
        const [L, a, b] = xyzD65ToOklab(xyz);
        return withAlpha(oklabToRgb(L, a, b), alpha);
      }
      return null;
    }
    default:
      return null;
  }
}
