import { parseColour, toHex } from '../analyse/colour-math';
import type { Tokens } from '../model/tokens';
import { cleanFamily, cleanText, colourEntries, fontEntries, radiusEntries, shadowEntries, spaceEntries, splitFamily, wants } from './model';
import { parseShadow } from './shadow';
import { EXTENSION_KEY, type ExportOptions } from './types';

const num = (value: number) => ({ $type: 'number', $value: value });
const group = <T>(es: { name: string; value: T }[], type: string) =>
  Object.fromEntries(es.map((e) => [e.name, { $type: type, $value: e.value }]));

export interface FigmaTextStyle { name: string; fontFamily: string; fontWeight: number; fontSize: number; lineHeight: number | null }
export interface FigmaShadowLayer { color: string; opacity: number; offsetX: number; offsetY: number; blur: number; spread: number }
export interface FigmaShadowStyle { name: string; layers: FigmaShadowLayer[] }

/** A shadow whose every layer has a colour Figma can take (hex plus opacity); null otherwise. */
function shadowStyle(name: string, css: string): FigmaShadowStyle | null {
  const layers: FigmaShadowLayer[] = [];
  for (const l of parseShadow(css) ?? []) {
    const c = parseColour(l.color);
    if (!c) return null;
    layers.push({ color: toHex({ ...c, a: 1 }).toUpperCase(), opacity: Math.round(c.a * 100) / 100, offsetX: l.offsetX, offsetY: l.offsetY, blur: l.blur, spread: l.spread });
  }
  return layers.length ? { name, layers } : null;
}

/**
 * Figma variables: colour, number and string only, one mode named Light. The type scale is three number variables per
 * step (`text/h1/size`, `line-height`, `weight`). Figma variables cannot hold a text style or a shadow, so those ride in
 * $extensions for the Site to Design Token Figma plugin, which creates them as text and effect styles.
 */
export function toFigma(t: Tokens, o: ExportOptions): string {
  const tree: Record<string, unknown> = {};
  const put = (name: string, g: Record<string, unknown>) => { if (Object.keys(g).length) tree[name] = g; };
  const typography = wants(o, 'typography');
  if (wants(o, 'colours')) put('color', group(colourEntries(t), 'color'));
  if (typography) {
    put('font', group(fontEntries(t), 'string'));
    put('text', Object.fromEntries(t.type.map((x) => [x.name, {
      size: num(x.sizePx),
      ...(x.lineHeightPx !== null ? { 'line-height': num(x.lineHeightPx) } : {}),
      weight: num(x.weight),
    }])));
  }
  if (wants(o, 'spacing')) put('space', group(spaceEntries(t), 'number'));
  if (wants(o, 'radius')) put('radius', group(radiusEntries(t), 'number'));

  const textStyles: FigmaTextStyle[] = typography
    ? t.type.map((x) => ({
      name: x.name, fontFamily: splitFamily(cleanFamily(x.family))[0] ?? '', fontWeight: x.weight, fontSize: x.sizePx, lineHeight: x.lineHeightPx,
    })).filter((s) => s.fontFamily !== '')
    : [];
  const shadowStyles = wants(o, 'shadows')
    ? shadowEntries(t).map((e) => shadowStyle(e.name, e.value)).filter((s): s is FigmaShadowStyle => s !== null)
    : [];
  const own = {
    site: cleanText(t.site, 80),
    ...(textStyles.length ? { textStyles } : {}),
    ...(shadowStyles.length ? { shadowStyles } : {}),
  };
  tree.$extensions = { 'com.figma': { modes: ['Light'] }, [EXTENSION_KEY]: own };
  return JSON.stringify(tree, null, 2) + '\n';
}
