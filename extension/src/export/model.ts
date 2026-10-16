import type { Tokens } from '../model/tokens';
import type { ExportOptions, Part } from './types';

export interface Entry<T = string> { name: string; value: T }

export const wants = (o: ExportOptions, p: Part): boolean => o.parts.includes(p);
export const px = (n: number): string => `${n}px`;

const SIZES = ['sm', 'md', 'lg', 'xl'];

export const colourEntries = (t: Tokens): Entry[] => t.colours.map((c) => ({ name: c.name, value: c.hex }));
export const fontEntries = (t: Tokens): Entry[] => t.fonts.map((f) => ({ name: f.name, value: f.family }));
export const spaceEntries = (t: Tokens): Entry<number>[] =>
  t.spacing.scale.map((v, i) => ({ name: String(i + 1), value: v }));
export const shadowEntries = (t: Tokens): Entry[] =>
  t.shadows.slice(0, SIZES.length).map((v, i) => ({ name: SIZES[i], value: v }));

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

/** Join non-empty sections with a blank line between them. */
export const joinSections = (sections: string[][]): string[] =>
  sections.filter((s) => s.length).flatMap((s, i) => (i ? ['', ...s] : s));

/** Lines for a comment-headed section, or none when the section has no data. */
export const section = (title: string, lines: string[]): string[] =>
  lines.length ? [`  /* ${title} */`, ...lines] : [];
