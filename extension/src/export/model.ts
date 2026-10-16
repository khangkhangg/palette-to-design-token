import type { Role, Tokens } from '../model/tokens';
import type { ExportOptions, Part } from './types';

export interface Entry<T = string> { name: string; value: T }

export const wants = (o: ExportOptions, p: Part): boolean => o.parts.includes(p);
export const px = (n: number): string => `${n}px`;

const SIZES = ['sm', 'md', 'lg', 'xl'];

export interface ColourEntry extends Entry {
  role: Role | null;
  /** For a contrast-fix token: the token it stands in for where AA contrast is needed (null if none matched). */
  fixes?: string | null;
}

const same = (a: string, b: string) => a.toUpperCase() === b.toUpperCase();

/**
 * Ruling R16: each contrast fix becomes a named token so DESIGN.md's "use only these tokens" stays true.
 * Named after the role of the side that changed (`accent-aa`, then `accent-aa-2`), or `fix-n` when that
 * colour is no token. A fix colour that is already a token, or already emitted, adds nothing.
 */
function fixEntries(t: Tokens): ColourEntry[] {
  const names = new Set(t.colours.map((c) => c.name));
  const known = t.colours.map((c) => c.hex);
  const out: ColourEntry[] = [];
  let n = 0;
  for (const p of t.contrast) {
    if (p.grade !== 'fail' || !p.fix) continue;
    const fgChanged = !same(p.fix.fg, p.fg);
    const from = fgChanged ? p.fg : p.bg;
    const to = fgChanged ? p.fix.fg : p.fix.bg;
    if (known.some((h) => same(h, to))) continue;
    const src = t.colours.find((c) => same(c.hex, from));
    let name: string;
    if (src) {
      name = `${src.role}-aa`;
      for (let k = 2; names.has(name); k++) name = `${src.role}-aa-${k}`;
    } else {
      do name = `fix-${++n}`; while (names.has(name));
    }
    names.add(name);
    known.push(to);
    out.push({ name, value: to, role: src?.role ?? null, fixes: src?.name ?? null });
  }
  return out;
}

/** Colour tokens, then the contrast-fix tokens (R16). */
export const colourEntries = (t: Tokens): ColourEntry[] => [
  ...t.colours.map((c) => ({ name: c.name, value: c.hex, role: c.role })),
  ...fixEntries(t),
];

/** The colour token whose value is `hex`, if any. */
export const colourTokenFor = (t: Tokens, hex: string): string | null =>
  colourEntries(t).find((e) => same(e.value, hex))?.name ?? null;

export const fontEntries = (t: Tokens): Entry[] => t.fonts.map((f) => ({ name: f.name, value: cleanFamily(f.family) }));
export const spaceEntries = (t: Tokens): Entry<number>[] =>
  t.spacing.scale.map((v, i) => ({ name: String(i + 1), value: v }));

const LENGTH = /^[+-]?(\d+\.?\d*|\.\d+)(px)?$/;

/** Blur, then |y|, of a shadow's largest layer (inset included); null when there is nothing to measure. */
function shadowSize(css: string): [number, number] | null {
  let best: [number, number] | null = null;
  for (const layer of css.replace(/\([^)]*\)/g, '()').split(',')) {
    const nums = layer.trim().split(/\s+/).filter((tok) => LENGTH.test(tok)).map(parseFloat);
    if (nums.length < 2) continue;
    const size: [number, number] = [nums[2] ?? 0, Math.abs(nums[1])];
    if (!best || size[0] > best[0] || (size[0] === best[0] && size[1] > best[1])) best = size;
  }
  return best;
}

/** The analyser keeps the 4 most used; they are named by size (blur, then y-offset), smallest `sm`. */
export function shadowEntries(t: Tokens): Entry[] {
  const sized = t.shadows.slice(0, SIZES.length).map((v) => ({ v, s: shadowSize(v) }));
  sized.sort((a, b) => (a.s && b.s ? a.s[0] - b.s[0] || a.s[1] - b.s[1] : Number(!a.s) - Number(!b.s)));
  return sized.map(({ v }, i) => ({ name: SIZES[i], value: v }));
}

export function radiusEntries(t: Tokens): Entry<number>[] {
  const sorted = [...t.radius].sort((a, b) => a - b);
  const sized = sorted.filter((r) => r < 9999).slice(0, SIZES.length);
  const out = sized.map((v, i) => ({ name: SIZES[i], value: v }));
  if (sorted.some((r) => r >= 9999)) out.push({ name: 'full', value: 9999 });
  return out;
}

/** Split a CSS font-family list on top-level commas and strip quotes. */
export function splitFamily(family: string): string[] {
  const out: string[] = [];
  let cur = '';
  let quote = '';
  for (const ch of family) {
    if (quote) {
      if (ch === quote) quote = '';
      else cur += ch;
    } else if (ch === '"' || ch === "'") quote = ch;
    else if (ch === ',') { out.push(cur.trim()); cur = ''; }
    else cur += ch;
  }
  out.push(cur.trim());
  return out.filter(Boolean);
}

const MAX_FAMILIES = 6;
const MAX_FAMILY_CHARS = 64;
// C0/C1 controls (newlines included) and line/paragraph separators
const CONTROL = /[\x00-\x1F\x7F-\x9F\u{2028}\u{2029}]/gu;
// characters that could end a CSS value, a JS string or a Markdown code span
const UNSAFE = /[`\\;{}<>"']/g;

/**
 * A page-controlled font-family made inert for every export: control characters become spaces and
 * unsafe characters are dropped; at most 6 names of at most 64 characters each. Names that are not
 * a single CSS identifier are double-quoted.
 */
export function cleanFamily(family: string): string {
  return splitFamily(family.replace(CONTROL, ' '))
    .map((n) => n.replace(UNSAFE, '').replace(/\s+/g, ' ').trim().slice(0, MAX_FAMILY_CHARS).trim())
    .filter(Boolean)
    .slice(0, MAX_FAMILIES)
    .map((n) => (/^-?[A-Za-z_][\w-]*$/.test(n) ? n : `"${n}"`))
    .join(', ');
}

/** Join non-empty sections with a blank line between them. */
export const joinSections = (sections: string[][]): string[] =>
  sections.filter((s) => s.length).flatMap((s, i) => (i ? ['', ...s] : s));

/** Lines for a comment-headed section, or none when the section has no data. */
export const section = (title: string, lines: string[]): string[] =>
  lines.length ? [`  /* ${title} */`, ...lines] : [];
