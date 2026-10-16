import type { ContrastPair, Role, Tokens } from '../model/tokens';
import { colourEntries, fontEntries, px, radiusEntries, shadowEntries, spaceEntries, wants } from './model';
import type { ExportOptions } from './types';

const USE_FOR: Record<Role, string> = {
  primary: 'links, primary buttons, nav',
  accent: 'highlights, badges, secondary CTAs',
  surface: 'page and card backgrounds',
  text: 'body copy and headings',
  border: 'dividers and outlines',
  other: '—',
};

const IMAGE = 'an image or gradient';
const bgLabel = (bg: string): string => (bg === 'none' ? IMAGE : bg);
/** The analyser already floors to 2dp; rounding again could turn a fail into 4.5. */
const ratio = (n: number | null): string => (n === null ? '' : String(n));

function contrastLine(p: ContrastPair): string | null {
  if (p.grade === 'unknown') return `- Check manually: ${p.fg} text on ${IMAGE}.`;
  if (p.grade !== 'fail') return null;
  const base = `- Do not put ${p.fg} text on ${bgLabel(p.bg)} (${ratio(p.ratio)}:1).`;
  return p.fix ? `${base} Use ${p.fix.fg} on ${p.fix.bg}.` : base;
}

export function toDesignMd(t: Tokens, o: ExportOptions): string {
  const blocks: string[][] = [
    [`# Design system — ${t.site}`],
    ['Use only these tokens for UI.', 'Do not invent new colours, fonts or spacing.'],
  ];
  const add = (title: string, lines: string[]): void => {
    if (lines.length) blocks.push([`## ${title}`, '', ...lines]);
  };

  if (wants(o, 'colours')) {
    const rows = t.colours.map((c, i) => {
      const e = colourEntries(t)[i];
      return `| ${c.role} | \`--color-${e.name}\` | ${e.value} | ${USE_FOR[c.role]} |`;
    });
    add('Colour roles', rows.length ? ['| Role | Token | Hex | Use for |', '| --- | --- | --- | --- |', ...rows] : []);
  }
  if (wants(o, 'typography')) {
    const fonts = fontEntries(t).map((f) => `- ${f.name[0].toUpperCase()}${f.name.slice(1)} font: ${f.value}`);
    const scale = t.type.map((x) => {
      const lh = x.lineHeightPx === null ? '' : `/${px(x.lineHeightPx)}`;
      return `- ${x.name}: ${x.family} ${x.weight}, ${px(x.sizePx)}${lh}`;
    });
    add('Typography', [...fonts, ...scale]);
  }
  if (wants(o, 'spacing')) {
    add('Spacing', spaceEntries(t).map((e) => `- \`--space-${e.name}\`: ${px(e.value)}`));
  }
  if (wants(o, 'radius')) {
    add('Radius', radiusEntries(t).map((e) => `- \`--radius-${e.name}\`: ${px(e.value)}`));
  }
  if (wants(o, 'shadows')) {
    add('Shadows', shadowEntries(t).map((e) => `- \`--shadow-${e.name}\`: ${e.value}`));
  }
  add('Contrast rules', t.contrast.map(contrastLine).filter((l): l is string => l !== null));

  return blocks.flatMap((b, i) => (i ? ['', ...b] : b)).join('\n') + '\n';
}
