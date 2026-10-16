import { describe, it, expect } from 'vitest';
import { analyseType, analyseSpacing, analyseRadius, analyseShadows, type TextSample } from '../../src/analyse/scales';

const HN = '"Helvetica Neue", Arial, sans-serif';
const t = (o: Partial<TextSample> & { sizePx: number; chars: number }): TextSample => ({
  family: HN, weight: 400, lineHeightPx: null, heading: false, mono: false, ...o,
});

const page = (): TextSample[] => [
  t({ sizePx: 16, chars: 8000, lineHeightPx: 24 }),
  t({ sizePx: 48, chars: 40, heading: true, weight: 700 }),
  t({ sizePx: 32, chars: 90, heading: true, weight: 700 }),
  t({ sizePx: 24, chars: 200, heading: true, weight: 600 }),
  t({ sizePx: 13, chars: 900 }),
  t({ sizePx: 15.7, chars: 100, lineHeightPx: 24 }),
];

describe('analyseType', () => {
  it('names sizes display/h1/h2/body/small and merges 15.7 into 16', () => {
    const { type } = analyseType(page());
    expect(type.map((x) => [x.name, x.sizePx])).toEqual([
      ['display', 48], ['h1', 32], ['h2', 24], ['body', 16], ['small', 13],
    ]);
    expect(type.find((x) => x.name === 'body')!.chars).toBe(8100);
    expect(type.find((x) => x.name === 'body')!.lineHeightPx).toBe(24);
  });
  it('uses h1 as the largest when under 40px', () => {
    const { type } = analyseType([t({ sizePx: 16, chars: 500 }), t({ sizePx: 36, chars: 20, heading: true }), t({ sizePx: 24, chars: 30, heading: true })]);
    expect(type.map((x) => x.name)).toEqual(['h1', 'h2', 'body']);
  });
  it('names smaller sizes small, caption, xs and drops the rest', () => {
    const { type } = analyseType([16, 14, 12, 11, 10].map((s, i) => t({ sizePx: s, chars: 1000 - i * 100 })));
    expect(type.map((x) => x.name)).toEqual(['body', 'small', 'caption', 'xs']);
  });
  it('drops larger sizes beyond h4', () => {
    const { type } = analyseType([t({ sizePx: 12, chars: 900 }), ...[50, 40, 30, 24, 20, 16].map((s) => t({ sizePx: s, chars: 10, heading: true }))]);
    expect(type.map((x) => x.name)).toEqual(['display', 'h1', 'h2', 'h3', 'h4', 'body']);
  });
  it('falls back to all samples for body when only headings exist', () => {
    const { type } = analyseType([t({ sizePx: 20, chars: 50, heading: true }), t({ sizePx: 30, chars: 10, heading: true })]);
    expect(type.map((x) => x.name)).toEqual(['h1', 'body']);
  });
  it('fonts: body, heading only when different, mono, families verbatim', () => {
    const same = analyseType(page()).fonts;
    expect(same).toEqual([{ name: 'body', family: HN }]);
    const serif = 'Georgia, serif';
    const mono = 'ui-monospace, "SF Mono", monospace';
    const { fonts } = analyseType([...page(), t({ sizePx: 48, chars: 400, heading: true, family: serif }), t({ sizePx: 14, chars: 50, family: mono, mono: true })]);
    expect(fonts).toEqual([{ name: 'body', family: HN }, { name: 'heading', family: serif }, { name: 'mono', family: mono }]);
  });
  it('empty input', () => {
    expect(analyseType([])).toEqual({ fonts: [], type: [] });
  });
});

describe('analyseSpacing', () => {
  it('base 4 when 8 misses 70%; zeros ignored; scale needs 2+ uses', () => {
    expect(analyseSpacing([4, 8, 8, 16, 24, 32, 12, 48, 0, 0, 1])).toEqual({ base: 4, scale: [8] });
  });
  it('base 8 at >= 70% multiples of 8', () => {
    expect(analyseSpacing([8, 8, 16, 16, 24, 24, 32, 5, 5, 40])).toEqual({ base: 8, scale: [5, 8, 16, 24] });
  });
  it('null base when neither fits', () => {
    expect(analyseSpacing([5, 5, 7, 7, 9])).toEqual({ base: null, scale: [5, 7] });
  });
  it('ignores values over 128 and negatives; caps at 12 most used, sorted', () => {
    const vals: number[] = [200, 200, -8, -8];
    for (let i = 1; i <= 14; i++) for (let k = 0; k < (i <= 12 ? 3 : 2); k++) vals.push(i * 4);
    const { scale } = analyseSpacing(vals);
    expect(scale).toEqual([4, 8, 12, 16, 20, 24, 28, 32, 36, 40, 44, 48]);
  });
  it('empty input', () => {
    expect(analyseSpacing([])).toEqual({ base: null, scale: [] });
  });
});

describe('analyseRadius', () => {
  it('top 4 by frequency, sorted asc, >= 999 normalised', () => {
    expect(analyseRadius([4, 4, 10, 10, 10, 9999, 1000, 2, 0])).toEqual([2, 4, 10, 9999]);
  });
  it('ties broken by smaller first', () => {
    expect(analyseRadius([1, 2, 3, 4, 5])).toEqual([1, 2, 3, 4]);
  });
  it('empty input', () => {
    expect(analyseRadius([])).toEqual([]);
  });
});

describe('analyseShadows', () => {
  it('ignores none/empty; top 4 by frequency; ties first seen', () => {
    const a = '0 1px 2px rgba(0,0,0,.1)', b = '0 4px 8px rgba(0,0,0,.2)', c = 'c', d = 'd', e = 'e';
    expect(analyseShadows(['none', '', a, b, b, c, d, e, e, e])).toEqual([e, b, a, c]);
  });
  it('empty input', () => {
    expect(analyseShadows([])).toEqual([]);
  });
});
