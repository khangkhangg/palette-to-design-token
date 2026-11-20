/** Pure: which tokens can be edited, and what the token set looks like once the user's edits are applied. No DOM. */
import { contrastRatio, parseColour } from '../analyse/colour-math';
import { grade, suggestFix } from '../analyse/contrast';
import { radiusEntries, spaceEntries } from '../export/model';
import type { ColourToken, ContrastPair, Tokens, TypeToken } from '../model/tokens';
import type { AddedToken, Edits, TokenSpec, TokenValue } from './types';

const FULL_RADIUS = 9999;

/**
 * The editable tokens of a scan. Spacing and radius have no names of their own, so their ids carry the scanned
 * value and their names are the ones the exports use (`space-3`, `radius-md`). The "full" radius is not editable.
 */
export function tokenSpecs(t: Tokens): TokenSpec[] {
  return [
    ...t.colours.map((c): TokenSpec => ({ id: `colour:${c.name}`, kind: 'colour', name: c.name, value: c.hex.toUpperCase() })),
    ...t.type.map((x): TokenSpec => ({ id: `type:${x.name}`, kind: 'type', name: `text-${x.name}`, value: x.sizePx })),
    ...spaceEntries(t).map((e): TokenSpec => ({ id: `space:${e.value}`, kind: 'space', name: `space-${e.name}`, value: e.value })),
    ...radiusEntries(t)
      .filter((e) => e.value < FULL_RADIUS)
      .map((e): TokenSpec => ({ id: `radius:${e.value}`, kind: 'radius', name: `radius-${e.name}`, value: e.value })),
  ];
}

const hexOf = (v: TokenValue): string => String(v).toUpperCase();
const numOf = (v: TokenValue): number => Number(v);
const uniqueSorted = (values: number[]): number[] => [...new Set(values)].sort((a, b) => a - b);

/** A type token at a new size keeps its proportions: the line height scales with the size. */
function resize(x: TypeToken, sizePx: number): TypeToken {
  if (sizePx === x.sizePx) return x;
  const lineHeightPx = x.lineHeightPx === null || !(x.sizePx > 0) ? x.lineHeightPx : Math.round((x.lineHeightPx * sizePx) / x.sizePx);
  return { ...x, sizePx, lineHeightPx };
}

/** Contrast pairs follow a colour that changed: the ratio, grade and suggested fix are worked out again. */
function recolour(pairs: ContrastPair[], moved: Map<string, string>): ContrastPair[] {
  if (!moved.size) return pairs;
  return pairs.map((p) => {
    const fg = moved.get(p.fg.toUpperCase()) ?? p.fg;
    const bg = moved.get(p.bg.toUpperCase()) ?? p.bg;
    if (fg === p.fg && bg === p.bg) return p;
    const f = parseColour(fg);
    const b = bg === 'none' ? null : parseColour(bg);
    const raw = f && b ? contrastRatio(f, b) : null;
    const g = grade(raw, p.large);
    const next: ContrastPair = {
      fg, bg, large: p.large, uses: p.uses, where: p.where, grade: g,
      ratio: raw === null ? null : Math.floor(raw * 100 + 1e-9) / 100,
    };
    const fix = g === 'fail' && f && b ? suggestFix(f, b, p.large) : null;
    if (fix) next.fix = fix;
    return next;
  });
}

function addedColour(a: AddedToken, t: Tokens): ColourToken {
  const from = a.from ? t.colours.find((c) => `colour:${c.name}` === a.from) : undefined;
  return { name: a.name, hex: hexOf(a.value), role: from?.role ?? 'other', weight: 0, interactive: from?.interactive ?? false };
}

function addedType(a: AddedToken, t: Tokens): TypeToken | null {
  const base = (a.from ? t.type.find((x) => `type:${x.name}` === a.from) : undefined) ?? t.type.find((x) => x.name === 'body') ?? t.type[0];
  if (!base) return null;
  return { ...resize(base, numOf(a.value)), name: a.name.replace(/^text-/, ''), chars: 0 };
}

/** The scanned tokens with the user's token changes and new tokens applied. One-off and page edits do not change tokens. */
export function applyEdits(t: Tokens, e: Edits): Tokens {
  const changed = Object.keys(e.values).length > 0;
  if (!changed && !e.added.length) return t;
  const value = <V extends TokenValue>(id: string, fallback: V): TokenValue => (id in e.values ? e.values[id] : fallback);
  const added = (kind: AddedToken['kind']) => e.added.filter((a) => a.kind === kind);

  const moved = new Map<string, string>();
  const colours = t.colours.map((c) => {
    const hex = hexOf(value(`colour:${c.name}`, c.hex));
    if (hex === c.hex.toUpperCase()) return c;
    moved.set(c.hex.toUpperCase(), hex);
    return { ...c, hex };
  });

  return {
    ...t,
    colours: [...colours, ...added('colour').map((a) => addedColour(a, t))],
    type: [
      ...t.type.map((x) => resize(x, numOf(value(`type:${x.name}`, x.sizePx)))),
      ...added('type').map((a) => addedType(a, t)).filter((x): x is TypeToken => x !== null),
    ],
    spacing: {
      ...t.spacing,
      scale: uniqueSorted([
        ...t.spacing.scale.map((v) => numOf(value(`space:${v}`, v))),
        ...added('space').map((a) => numOf(a.value)),
      ]).filter((v) => v > 0),
    },
    radius: uniqueSorted([
      ...t.radius.map((v) => (v >= FULL_RADIUS ? v : numOf(value(`radius:${v}`, v)))),
      ...added('radius').map((a) => numOf(a.value)),
    ]).filter((v) => v > 0),
    contrast: recolour(t.contrast, moved),
  };
}

export interface ChangeCounts { tokens: number; oneOffs: number; page: number }

export const countEdits = (e: Edits): ChangeCounts => ({
  tokens: Object.keys(e.values).length + e.added.length,
  oneOffs: e.oneOffs.length,
  page: e.page.length,
});

export const hasEdits = (e: Edits): boolean => {
  const c = countEdits(e);
  return c.tokens + c.oneOffs + c.page > 0;
};
