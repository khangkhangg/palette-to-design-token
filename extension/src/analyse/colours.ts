/** Pure colour clustering and role assignment. No DOM. */
import { composite, deltaE, parseColour, toHex, toOklch, type RGBA } from './colour-math';
import type { ColourToken, Role } from '../model/tokens';

export type ColourKind = 'text' | 'bg' | 'border' | 'fill';
export interface ColourSample {
  css: string;
  kind: ColourKind;
  /** area px² for bg, characters for text, perimeter px for border, area for fill */
  weight: number;
  interactive: boolean;
  nav: boolean;
}

const MERGE_DELTA_E = 2.5;
const MIN_WEIGHT = 0.005;
const CHROMATIC = 0.04;
const ACCENT_HUE_GAP = 30;
const WHITE: RGBA = { r: 255, g: 255, b: 255, a: 1 };

interface Cluster {
  rgb: RGBA;
  hex: string;
  weight: number;
  interactive: boolean;
  /** share of link/button/nav use: text and bg only, each normalised over its interactive or nav samples */
  actionWeight: number;
  /** weight of interactive samples */
  interactiveWeight: number;
  kinds: Record<ColourKind, number>;
  L: number;
  C: number;
  h: number;
}

interface Prepared { rgb: RGBA; kind: ColourKind; weight: number; action: number; interactive: boolean; nav: boolean }

const hueGap = (a: number, b: number) => {
  const d = Math.abs(a - b) % 360;
  return d > 180 ? 360 - d : d;
};

function prepare(samples: ColourSample[]): Prepared[] {
  const out: Prepared[] = [];
  for (const smp of samples) {
    if (!(smp.weight > 0) || !Number.isFinite(smp.weight)) continue;
    const c = parseColour(smp.css);
    if (!c || c.a <= 0) continue;
    out.push({ rgb: composite(c, WHITE), kind: smp.kind, weight: smp.weight, action: 0, interactive: smp.interactive, nav: smp.nav });
  }
  // Action share: the same normalisation over interactive/nav samples only. As a share of the whole page a
  // button's area is negligible beside the page background, so link characters would always outvote it.
  // Only text and bg vote: fill and border are rare in the action set, so normalised per kind a lone nav
  // logo path or outlined button would get a whole kind's share.
  const acts = out.filter((p) => (p.interactive || p.nav) && (p.kind === 'text' || p.kind === 'bg'));
  const actionTotals: Partial<Record<ColourKind, number>> = {};
  for (const p of acts) actionTotals[p.kind] = (actionTotals[p.kind] ?? 0) + p.weight;
  const actionKinds = Object.keys(actionTotals).length;
  for (const p of acts) p.action = p.weight / (actionTotals[p.kind] as number) / actionKinds;
  // Each kind has its own units: normalise per kind, then share equally between kinds present.
  const totals: Partial<Record<ColourKind, number>> = {};
  for (const p of out) totals[p.kind] = (totals[p.kind] ?? 0) + p.weight;
  const kindCount = Object.keys(totals).length;
  for (const p of out) p.weight = p.weight / (totals[p.kind] as number) / kindCount;
  return out;
}

function cluster(prepared: Prepared[]): Cluster[] {
  const sorted = [...prepared].sort((a, b) => b.weight - a.weight);
  const clusters: Cluster[] = [];
  for (const p of sorted) {
    let cl = clusters.find((c) => deltaE(c.rgb, p.rgb) < MERGE_DELTA_E);
    if (!cl) {
      const { L, C, h } = toOklch(p.rgb);
      cl = {
        rgb: p.rgb, hex: toHex(p.rgb), weight: 0, interactive: false, actionWeight: 0, interactiveWeight: 0,
        kinds: { text: 0, bg: 0, border: 0, fill: 0 }, L, C, h,
      };
      clusters.push(cl);
    }
    cl.weight += p.weight;
    cl.kinds[p.kind] += p.weight;
    if (p.interactive) {
      cl.interactive = true;
      cl.interactiveWeight += p.weight;
    }
    cl.actionWeight += p.action;
  }
  return clusters;
}

const dominantKind = (c: Cluster): ColourKind =>
  (Object.keys(c.kinds) as ColourKind[]).reduce((best, k) => (c.kinds[k] > c.kinds[best] ? k : best), 'bg');

/**
 * Output order: primary, accent, primary-n, other chromatic, surface, text, border, other; by weight within a role.
 * Surfaces run light to dark, or dark to light when the background covering the most area is dark.
 */
export function analyseColours(samples: ColourSample[]): ColourToken[] {
  const clusters = cluster(prepare(samples)).filter((c) => c.weight >= MIN_WEIGHT || c.interactive);
  if (!clusters.length) return [];

  const byWeight = (a: Cluster, b: Cluster) => b.weight - a.weight;
  const roles = new Map<Cluster, Role>();
  const order: Cluster[] = [];
  const assign = (c: Cluster, r: Role) => {
    roles.set(c, r);
    order.push(c);
  };

  const chromatic = clusters.filter((c) => c.C > CHROMATIC);
  const primary = [...chromatic].sort((a, b) => b.actionWeight - a.actionWeight || byWeight(a, b))[0];
  if (primary && primary.actionWeight > 0) {
    assign(primary, 'primary');
    const far = chromatic.filter((c) => c !== primary && hueGap(c.h, primary.h) > ACCENT_HUE_GAP);
    const accent = [...far].sort((a, b) => b.interactiveWeight - a.interactiveWeight || byWeight(a, b))[0];
    if (accent) assign(accent, 'accent');
    const rest = chromatic.filter((c) => c !== primary && c !== accent).sort(byWeight);
    for (const c of rest.filter((c) => hueGap(c.h, primary.h) <= ACCENT_HUE_GAP)) assign(c, 'primary');
    for (const c of rest.filter((c) => hueGap(c.h, primary.h) > ACCENT_HUE_GAP)) assign(c, 'other');
  } else {
    // No interactive or nav use: nothing qualifies as primary or accent.
    for (const c of [...chromatic].sort(byWeight)) assign(c, 'other');
  }

  const neutral = clusters.filter((c) => c.C <= CHROMATIC);
  const kindRole: Record<ColourKind, Role> = { bg: 'surface', text: 'text', border: 'border', fill: 'other' };
  for (const k of ['bg', 'text', 'border', 'fill'] as ColourKind[]) {
    const group = neutral.filter((c) => dominantKind(c) === k);
    if (k === 'bg' && group.length) {
      const dark = group.reduce((a, b) => (b.weight > a.weight ? b : a)).L < 0.5;
      group.sort(dark ? (a, b) => a.L - b.L : (a, b) => b.L - a.L);
    } else {
      group.sort(byWeight);
    }
    for (const c of group) assign(c, kindRole[k]);
  }

  const counts = new Map<Role, number>();
  return order.map((c) => {
    const role = roles.get(c) as Role;
    const n = (counts.get(role) ?? 0) + 1;
    counts.set(role, n);
    return { name: n === 1 ? role : `${role}-${n}`, hex: c.hex, role, weight: c.weight, interactive: c.interactive };
  });
}
