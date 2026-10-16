import type { Tokens } from '../model/tokens';
import { colourEntries, fontEntries, radiusEntries, spaceEntries, wants } from './model';
import type { ExportOptions } from './types';

const group = <T>(es: { name: string; value: T }[], type: string) =>
  Object.fromEntries(es.map((e) => [e.name, { $type: type, $value: e.value }]));

/** Figma variables: colour, number and string only (no composite or shadow types), one mode named Light. */
export function toFigma(t: Tokens, o: ExportOptions): string {
  const tree: Record<string, unknown> = {};
  const put = (name: string, g: Record<string, unknown>) => { if (Object.keys(g).length) tree[name] = g; };
  if (wants(o, 'colours')) put('color', group(colourEntries(t), 'color'));
  if (wants(o, 'typography')) put('font', group(fontEntries(t), 'string'));
  if (wants(o, 'spacing')) put('space', group(spaceEntries(t), 'number'));
  if (wants(o, 'radius')) put('radius', group(radiusEntries(t), 'number'));
  tree.$extensions = { 'com.figma': { modes: ['Light'] } };
  return JSON.stringify(tree, null, 2) + '\n';
}
