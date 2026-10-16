import type { Tokens, TypeToken } from '../model/tokens';

export interface TextSample {
  family: string; sizePx: number; weight: number; lineHeightPx: number | null;
  chars: number; heading: boolean; mono: boolean;
}

const LARGER = ['h4', 'h3', 'h2', 'h1'];
const SMALLER = ['small', 'caption', 'xs'];

interface Group { family: string; size: number; weight: number; chars: number; bodyChars: number; lh: Map<number, number> }
interface Cluster { groups: Group[]; chars: number; bodyChars: number }

/** Most frequent key by count; ties go to the first seen. */
function top<T>(counts: Map<T, number>): T | undefined {
  let best: T | undefined; let n = -1;
  for (const [k, v] of counts) if (v > n) { best = k; n = v; }
  return best;
}

function tally<T>(values: T[]): Map<T, number> {
  const m = new Map<T, number>();
  for (const v of values) m.set(v, (m.get(v) ?? 0) + 1);
  return m;
}

export function analyseType(samples: TextSample[]): { fonts: Tokens['fonts']; type: TypeToken[] } {
  const valid = samples.filter((s) => s.sizePx > 0 && s.chars > 0);
  if (!valid.length) return { fonts: [], type: [] };

  const groups = new Map<string, Group>();
  for (const s of valid) {
    const size = s.sizePx;
    const key = `${s.family}|${size}|${s.weight}`;
    let g = groups.get(key);
    if (!g) { g = { family: s.family, size, weight: s.weight, chars: 0, bodyChars: 0, lh: new Map() }; groups.set(key, g); }
    g.chars += s.chars;
    if (!s.heading) g.bodyChars += s.chars;
    if (s.lineHeightPx !== null) g.lh.set(s.lineHeightPx, (g.lh.get(s.lineHeightPx) ?? 0) + 1);
  }

  const sorted = [...groups.values()].sort((a, b) => a.size - b.size);
  const clusters: Cluster[] = [];
  let anchor = NaN;
  for (const g of sorted) {
    if (clusters.length && g.size - anchor <= 0.5) {
      const c = clusters[clusters.length - 1];
      c.groups.push(g); c.chars += g.chars; c.bodyChars += g.bodyChars;
    } else {
      anchor = g.size;
      clusters.push({ groups: [g], chars: g.chars, bodyChars: g.bodyChars });
    }
  }

  const tokenOf = (c: Cluster, name: string): TypeToken => {
    const lead = c.groups.reduce((a, b) => (b.chars > a.chars ? b : a));
    const lh = new Map<number, number>();
    for (const g of c.groups) for (const [k, v] of g.lh) lh.set(k, (lh.get(k) ?? 0) + v);
    return { name, family: lead.family, sizePx: lead.size, weight: lead.weight, lineHeightPx: top(lh) ?? null, chars: c.chars };
  };
  const sizeOf = (c: Cluster) => c.groups.reduce((a, b) => (b.chars > a.chars ? b : a)).size;

  const anyBody = clusters.some((c) => c.bodyChars > 0);
  const metric = (c: Cluster) => (anyBody ? c.bodyChars : c.chars);
  const bodyCluster = clusters.reduce((a, b) => (metric(b) > metric(a) ? b : a));
  const bodySize = sizeOf(bodyCluster);

  const byDesc = clusters.filter((c) => c !== bodyCluster).sort((a, b) => sizeOf(b) - sizeOf(a));
  const larger = byDesc.filter((c) => sizeOf(c) > bodySize);
  const smaller = byDesc.filter((c) => sizeOf(c) <= bodySize);

  const largerNames = larger.length && sizeOf(larger[0]) >= 40 ? ['display', 'h1', 'h2', 'h3', 'h4'] : [...LARGER].reverse();
  const type: TypeToken[] = [
    ...larger.slice(0, largerNames.length).map((c, i) => tokenOf(c, largerNames[i])),
    tokenOf(bodyCluster, 'body'),
    ...smaller.slice(0, SMALLER.length).map((c, i) => tokenOf(c, SMALLER[i])),
  ];

  const fonts: Tokens['fonts'] = [];
  const body = type.find((t) => t.name === 'body')!;
  fonts.push({ name: 'body', family: body.family });
  const big = type.find((t) => t.name !== 'body' && t.sizePx > body.sizePx);
  if (big && big.family !== body.family) fonts.push({ name: 'heading', family: big.family });
  const monoChars = new Map<string, number>();
  for (const s of valid) if (s.mono) monoChars.set(s.family, (monoChars.get(s.family) ?? 0) + s.chars);
  const mono = top(monoChars);
  if (mono !== undefined) fonts.push({ name: 'mono', family: mono });

  return { fonts, type };
}

export function analyseSpacing(values: number[]): Tokens['spacing'] {
  const v = values.filter((x) => x > 0);
  if (!v.length) return { base: null, scale: [] };
  const share = (m: number) => v.filter((x) => x % m === 0).length / v.length;
  const base = share(8) >= 0.7 ? 8 : share(4) >= 0.7 ? 4 : null;
  const counts = tally(v.filter((x) => x <= 128));
  const used = [...counts].filter(([, n]) => n >= 2);
  used.sort((a, b) => b[1] - a[1] || a[0] - b[0]);
  const scale = used.slice(0, 12).map(([x]) => x).sort((a, b) => a - b);
  return { base, scale };
}

export function analyseRadius(values: number[]): number[] {
  const counts = tally(values.filter((x) => x > 0).map((x) => (x >= 999 ? 9999 : x)));
  return [...counts]
    .sort((a, b) => b[1] - a[1] || a[0] - b[0])
    .slice(0, 4)
    .map(([x]) => x)
    .sort((a, b) => a - b);
}

export function analyseShadows(values: string[]): string[] {
  const counts = tally(values.filter((s) => s.trim() !== '' && s.trim().toLowerCase() !== 'none'));
  return [...counts].sort((a, b) => b[1] - a[1]).slice(0, 4).map(([s]) => s); // stable sort keeps first-seen on ties
}
