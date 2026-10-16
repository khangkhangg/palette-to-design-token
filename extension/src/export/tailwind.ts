import type { Tokens } from '../model/tokens';
import {
  colourEntries, fontEntries, joinSections, px, radiusEntries, section, shadowEntries, spaceEntries, splitFamily, wants,
} from './model';
import type { ExportOptions } from './types';

/** Why spacing tokens are space-n: numeric keys would collide with Tailwind's own spacing scale. */
const SPACING_NOTE =
  "Named space-1, space-2… (p-space-3, gap-space-2) so they add to Tailwind's numeric scale instead of replacing p-1, p-2…";

export function toTailwind4(t: Tokens, o: ExportOptions): string {
  const typography: string[] = [];
  if (wants(o, 'typography')) {
    for (const f of fontEntries(t)) typography.push(`  --font-${f.name}: ${f.value};`);
    for (const x of t.type) {
      typography.push(`  --text-${x.name}: ${px(x.sizePx)};`);
      if (x.lineHeightPx !== null) typography.push(`  --text-${x.name}--line-height: ${px(x.lineHeightPx)};`);
      typography.push(`  --text-${x.name}--font-weight: ${x.weight};`);
    }
  }
  const spacing: string[] = [];
  if (wants(o, 'spacing')) {
    const entries = spaceEntries(t);
    if (entries.length) spacing.push(`  /* ${SPACING_NOTE} */`);
    // Setting --spacing would rescale every numeric utility (p-4 = 4 × --spacing): offered, not applied.
    if (t.spacing.base !== null) {
      const base = px(t.spacing.base);
      spacing.push(`  /* detected base: ${base} — set --spacing: ${base}; to adopt it */`);
    }
    for (const e of entries) spacing.push(`  --spacing-space-${e.name}: ${px(e.value)};`);
  }
  const body = joinSections([
    section('Colours', wants(o, 'colours') ? colourEntries(t).map((e) => `  --color-${e.name}: ${e.value};`) : []),
    section('Typography', typography),
    section('Spacing', spacing),
    section('Radius', wants(o, 'radius') ? radiusEntries(t).map((e) => `  --radius-${e.name}: ${px(e.value)};`) : []),
    section('Shadows', wants(o, 'shadows') ? shadowEntries(t).map((e) => `  --shadow-${e.name}: ${e.value};`) : []),
  ]);
  return ['@import "tailwindcss";', '', '@theme {', ...body, '}', ''].join('\n');
}

const key = (k: string) => (/^[A-Za-z_$][\w$]*$/.test(k) ? k : JSON.stringify(k));
const str = (s: string) => JSON.stringify(s);
const kv = (es: { name: string; value: string }[]) => es.map((e) => `${key(e.name)}: ${str(e.value)}`);
const group = (name: string, rows: string[], note?: string) =>
  rows.length
    ? [`      ${name}: {`, ...(note ? [`        // ${note}`] : []), ...rows.map((r) => `        ${r},`), '      },']
    : [];

export function toTailwind3(t: Tokens, o: ExportOptions): string {
  const typo = wants(o, 'typography');
  const fontSize = t.type.map((x) => {
    const opts = [
      ...(x.lineHeightPx !== null ? [`lineHeight: ${str(px(x.lineHeightPx))}`] : []),
      `fontWeight: ${str(String(x.weight))}`,
    ];
    return `${key(x.name)}: [${str(px(x.sizePx))}, { ${opts.join(', ')} }]`;
  });
  const families = fontEntries(t).map((f) => `${key(f.name)}: [${splitFamily(f.value).map(str).join(', ')}]`);
  const sized = (es: { name: string; value: number }[]) => kv(es.map((e) => ({ name: e.name, value: px(e.value) })));
  const groups = [
    group('colors', wants(o, 'colours') ? kv(colourEntries(t)) : []),
    group('fontFamily', typo ? families : []),
    group('fontSize', typo ? fontSize : []),
    group(
      'spacing',
      wants(o, 'spacing') ? sized(spaceEntries(t).map((e) => ({ ...e, name: `space-${e.name}` }))) : [],
      SPACING_NOTE,
    ),
    group('borderRadius', wants(o, 'radius') ? sized(radiusEntries(t)) : []),
    group('boxShadow', wants(o, 'shadows') ? kv(shadowEntries(t)) : []),
  ].flat();
  const extend = groups.length ? ['    extend: {', ...groups, '    },'] : ['    extend: {},'];
  return [
    "/** @type {import('tailwindcss').Config} */",
    'export default {',
    '  theme: {',
    ...extend,
    '  },',
    '};',
    '',
  ].join('\n');
}
