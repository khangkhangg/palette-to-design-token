import { describe, it, expect } from 'vitest';
import { grade, analyseContrast, suggestFix, type PairSample } from '../../src/analyse/contrast';
import { parseColour, toOklch, contrastRatio } from '../../src/analyse/colour-math';

const c = (s: string) => parseColour(s)!;
const sample = (o: Partial<PairSample> = {}): PairSample => ({
  fg: '#000000', bg: '#ffffff', chars: 10, sizePx: 16, weight: 400, where: 'body text', ...o,
});

describe('grade', () => {
  it('grades by threshold', () => {
    expect(grade(7.6, false)).toBe('AAA');
    expect(grade(5, false)).toBe('AA');
    expect(grade(3.2, true)).toBe('AA-large');
    expect(grade(3.2, false)).toBe('fail');
    expect(grade(null, false)).toBe('unknown');
    expect(grade(null, true)).toBe('unknown');
  });
});

describe('analyseContrast', () => {
  it('fails white on #E0763A with a bg-only fix', () => {
    const [p] = analyseContrast([sample({ fg: '#ffffff', bg: '#E0763A' })]);
    expect(p.grade).toBe('fail');
    expect(p.fix).toBeDefined();
    expect(p.fix!.ratio).toBeGreaterThanOrEqual(4.5);
    expect(p.fix!.fg).toBe('#FFFFFF');
    const a = toOklch(c('#E0763A'));
    const b = toOklch(c(p.fix!.bg));
    const dh = Math.abs(((a.h - b.h + 540) % 360) - 180);
    expect(dh).toBeLessThanOrEqual(2);
    expect(contrastRatio(c(p.fix!.fg), c(p.fix!.bg))).toBeGreaterThanOrEqual(4.5);
  });
  it('gives no fix to black on white', () => {
    const [p] = analyseContrast([sample()]);
    expect(p.grade).toBe('AAA');
    expect(p.fix).toBeUndefined();
  });
  it('composites a semi-transparent fg before rating', () => {
    const [p] = analyseContrast([sample({ fg: 'rgba(0,0,0,.6)' })]);
    expect(p.ratio).toBeGreaterThan(5.6);
    expect(p.ratio).toBeLessThan(5.8);
    expect(p.fg).toBe('#666666');
  });
  it('floors the stored ratio to 2dp and grades from the raw ratio', () => {
    // #5E7F5D on white is 4.49703...:1, a fail that rounding would show as 4.5
    const [p] = analyseContrast([sample({ fg: '#5E7F5D' })]);
    expect(p.ratio).toBe(4.49);
    expect(p.grade).toBe('fail');
  });
  it('treats null bg as unknown', () => {
    const [p] = analyseContrast([sample({ bg: null })]);
    expect(p.ratio).toBeNull();
    expect(p.grade).toBe('unknown');
    expect(p.fix).toBeUndefined();
  });
  it('merges identical pairs and counts uses', () => {
    const r = analyseContrast([sample(), sample(), sample({ where: 'headings' })]);
    expect(r).toHaveLength(1);
    expect(r[0].uses).toBe(3);
    expect(r[0].where).toBe('body text');
  });
  it('detects large text', () => {
    expect(analyseContrast([sample({ sizePx: 24 })])[0].large).toBe(true);
    expect(analyseContrast([sample({ sizePx: 18.66, weight: 700 })])[0].large).toBe(true);
    expect(analyseContrast([sample({ sizePx: 18.66, weight: 400 })])[0].large).toBe(false);
  });
  it('skips unparsable fg and treats unparsable bg as unknown', () => {
    expect(analyseContrast([sample({ fg: 'nope' })])).toEqual([]);
    expect(analyseContrast([sample({ bg: 'nope' })])[0].grade).toBe('unknown');
  });
  it('caps at 12, keeping every fail', () => {
    const many: PairSample[] = [];
    for (let i = 0; i < 20; i++) many.push(sample({ fg: `rgb(${i * 5},0,0)`, bg: '#ffffff' }), sample({ fg: `rgb(${i * 5},0,0)`, bg: '#ffffff' }));
    for (let i = 0; i < 3; i++) many.push(sample({ fg: `rgb(255,255,${200 + i})`, bg: '#ffffff' }));
    const r = analyseContrast(many);
    expect(r).toHaveLength(12);
    expect(r.filter((p) => p.grade === 'fail')).toHaveLength(3);
  });
});

describe('suggestFix', () => {
  it('returns null when no step passes (white on white)', () => {
    expect(suggestFix(c('#555555'), c('#808080'), false)).not.toBeNull();
    expect(suggestFix(c('#ffffff'), c('#ffffff'), false)).toBeNull();
  });
});
