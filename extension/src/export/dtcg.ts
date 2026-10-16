import type { Tokens } from '../model/tokens';
import {
  cleanFamily, colourEntries, fontEntries, radiusEntries, shadowEntries, spaceEntries, splitFamily, wants,
} from './model';
import { parseShadow, type ShadowLayer } from './shadow';
import type { ExportOptions } from './types';

const dim = (value: number) => ({ value, unit: 'px' });
type Group = Record<string, { $type: string; $value: unknown }>;

const group = <T>(es: { name: string; value: T }[], type: string, f: (v: T) => unknown): Group =>
  Object.fromEntries(es.map((e) => [e.name, { $type: type, $value: f(e.value) }]));

const shadowValue = (layers: ShadowLayer[]) => {
  const objs = layers.map((s) => ({
    color: s.color,
    offsetX: dim(s.offsetX),
    offsetY: dim(s.offsetY),
    blur: dim(s.blur),
    spread: dim(s.spread),
  }));
  return objs.length === 1 ? objs[0] : objs;
};

export function toDtcg(t: Tokens, o: ExportOptions): string {
  const out: Record<string, Group> = {};
  const put = (name: string, g: Group) => { if (Object.keys(g).length) out[name] = g; };

  if (wants(o, 'colours')) put('color', group(colourEntries(t), 'color', (v) => v));
  if (wants(o, 'typography')) {
    put('font', group(fontEntries(t), 'fontFamily', splitFamily));
    put('text', Object.fromEntries(t.type.map((x) => [x.name, {
      $type: 'typography',
      $value: {
        fontFamily: splitFamily(cleanFamily(x.family)),
        fontSize: dim(x.sizePx),
        fontWeight: x.weight,
        ...(x.lineHeightPx !== null ? { lineHeight: dim(x.lineHeightPx) } : {}),
      },
    }])));
  }
  if (wants(o, 'spacing')) put('space', group(spaceEntries(t), 'dimension', dim));
  if (wants(o, 'radius')) put('radius', group(radiusEntries(t), 'dimension', dim));
  if (wants(o, 'shadows')) {
    const parsed = shadowEntries(t).flatMap((e) => {
      const layers = parseShadow(e.value);
      return layers ? [{ name: e.name, value: layers }] : [];
    });
    put('shadow', group(parsed, 'shadow', shadowValue));
  }
  return JSON.stringify(out, null, 2) + '\n';
}
