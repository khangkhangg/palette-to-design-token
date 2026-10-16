import { parseColour, toHex, composite, toOklch, fromOklch, contrastRatio, type RGBA } from './colour-math';
import type { ContrastPair, Grade } from '../model/tokens';

export interface PairSample {
  fg: string; bg: string | null; chars: number; sizePx: number; weight: number;
  where: ContrastPair['where']; // bg null = image/gradient behind
}

const WHITE: RGBA = { r: 255, g: 255, b: 255, a: 1 };
const MAX_PAIRS = 12;

export function grade(ratio: number | null, large: boolean): Grade {
  if (ratio === null) return 'unknown';
  if (ratio >= 7) return 'AAA';
  if (ratio >= 4.5) return 'AA';
  if (large && ratio >= 3) return 'AA-large';
  return 'fail';
}

export function suggestFix(fg: RGBA, bg: RGBA, large: boolean): { fg: string; bg: string; ratio: number } | null {
  const target = large ? 3 : 4.5;
  const f = toOklch(fg);
  const b = toOklch(bg);
  const moveFg = f.C > b.C; // ties -> bg
  const mover = moveFg ? f : b;
  const otherLighter = moveFg ? b.L > f.L : f.L > b.L;
  // the other side is lighter -> darken the mover; otherwise lighten it
  const dir = otherLighter ? -0.01 : 0.01;
  for (let i = 1; i <= 100; i++) {
    const L = Math.min(1, Math.max(0, mover.L + dir * i));
    const next = fromOklch(L, mover.C, mover.h);
    const nf = moveFg ? next : fg;
    const nb = moveFg ? bg : next;
    const ratio = contrastRatio(nf, nb);
    if (ratio >= target) return { fg: toHex(nf), bg: toHex(nb), ratio: Math.round(ratio * 100) / 100 };
  }
  return null;
}

interface Group {
  fg: RGBA; bg: RGBA | null; large: boolean; uses: number;
  where: Map<ContrastPair['where'], number>;
}

export function analyseContrast(samples: PairSample[]): ContrastPair[] {
  const groups = new Map<string, Group>();
  for (const s of samples) {
    const fg0 = parseColour(s.fg);
    if (!fg0) continue;
    let bg: RGBA | null = null;
    if (s.bg !== null) {
      const parsed = parseColour(s.bg);
      if (parsed) bg = parsed.a < 1 ? composite(parsed, WHITE) : parsed;
    }
    const fg = bg && fg0.a < 1 ? composite(fg0, bg) : fg0;
    const large = s.sizePx >= 24 || (s.sizePx >= 18.66 && s.weight >= 700);
    const key = `${toHex(fg)}|${bg ? toHex(bg) : 'none'}|${large}`;
    let g = groups.get(key);
    if (!g) {
      g = { fg, bg, large, uses: 0, where: new Map() };
      groups.set(key, g);
    }
    g.uses++;
    g.where.set(s.where, (g.where.get(s.where) ?? 0) + 1);
  }

  const pairs: ContrastPair[] = [];
  for (const g of groups.values()) {
    let where: ContrastPair['where'] = 'other';
    let best = 0;
    for (const [w, n] of g.where) if (n > best) { best = n; where = w; } // Map keeps insertion order: ties -> first seen
    const rawRatio = g.bg ? contrastRatio(g.fg, g.bg) : null;
    const gr = grade(rawRatio, g.large);
    const pair: ContrastPair = {
      fg: toHex(g.fg),
      bg: g.bg ? toHex(g.bg) : 'none',
      // floored, not rounded, so a failing 4.497 is never stored (or shown) as a passing 4.5
      ratio: rawRatio === null ? null : Math.floor(rawRatio * 100 + 1e-9) / 100,
      large: g.large, uses: g.uses, where, grade: gr,
    };
    if (gr === 'fail' && g.bg) {
      const fix = suggestFix(g.fg, g.bg, g.large);
      if (fix) pair.fix = fix;
    }
    pairs.push(pair);
  }

  const byUses = (a: ContrastPair, b: ContrastPair) => b.uses - a.uses;
  const fails = pairs.filter((p) => p.grade === 'fail').sort(byUses);
  const rest = pairs.filter((p) => p.grade !== 'fail').sort(byUses);
  return [...fails, ...rest].slice(0, MAX_PAIRS).sort(
    (a, b) => b.uses - a.uses || Number(b.grade === 'fail') - Number(a.grade === 'fail'),
  );
}
