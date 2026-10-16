import type { Tokens } from '../model/tokens';
import {
  colourEntries, fontEntries, joinSections, px, radiusEntries, section, shadowEntries, spaceEntries, wants,
} from './model';
import type { ExportOptions } from './types';

export function toCss(t: Tokens, o: ExportOptions): string {
  const typography: string[] = [];
  if (wants(o, 'typography')) {
    for (const f of fontEntries(t)) typography.push(`  --font-${f.name}: ${f.value};`);
    for (const x of t.type) {
      typography.push(`  --text-${x.name}: ${px(x.sizePx)};`);
      if (x.lineHeightPx !== null) typography.push(`  --text-${x.name}--line-height: ${px(x.lineHeightPx)};`);
      typography.push(`  --text-${x.name}--font-weight: ${x.weight};`);
    }
  }
  const body = joinSections([
    section('Colours', wants(o, 'colours') ? colourEntries(t).map((e) => `  --color-${e.name}: ${e.value};`) : []),
    section('Typography', typography),
    section('Spacing', wants(o, 'spacing') ? spaceEntries(t).map((e) => `  --space-${e.name}: ${px(e.value)};`) : []),
    section('Radius', wants(o, 'radius') ? radiusEntries(t).map((e) => `  --radius-${e.name}: ${px(e.value)};`) : []),
    section('Shadows', wants(o, 'shadows') ? shadowEntries(t).map((e) => `  --shadow-${e.name}: ${e.value};`) : []),
  ]);
  return [':root {', ...body, '}', ''].join('\n');
}
