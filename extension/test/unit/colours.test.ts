import { describe, it, expect } from 'vitest';
import { analyseColours, type ColourSample } from '../../src/analyse/colours';

const s = (css: string, kind: ColourSample['kind'], weight: number, interactive = false, nav = false): ColourSample => ({
  css, kind, weight, interactive, nav,
});

const page = (): ColourSample[] => [
  s('#FFFDF8', 'bg', 900_000),
  s('#F3EEE4', 'bg', 200_000),
  s('#1B1A17', 'text', 5000),
  s('#5C574E', 'text', 1500),
  s('#1F5E4B', 'bg', 60_000, true),
  s('#1F5E4B', 'fill', 4000, true, true),
  s('#E0763A', 'bg', 20_000, true),
  s('#E4DDD0', 'border', 3000),
  s('#3FA37F', 'fill', 10_000),
];

const byName = (t: ReturnType<typeof analyseColours>, n: string) => t.find((x) => x.name === n);

describe('analyseColours', () => {
  it('merges near-identical colours, keeping the heavier hex', () => {
    const t = analyseColours([s('#FFFDF9', 'bg', 10_000), s('#FFFDF8', 'bg', 900_000)]);
    expect(t).toHaveLength(1);
    expect(t[0].hex).toBe('#FFFDF8');
    expect(t[0].weight).toBeCloseTo(1, 5);
  });

  it('assigns roles on a synthetic page', () => {
    const t = analyseColours(page());
    expect(byName(t, 'surface')?.hex).toBe('#FFFDF8');
    expect(byName(t, 'surface-2')?.hex).toBe('#F3EEE4');
    expect(byName(t, 'text')?.hex).toBe('#1B1A17');
    expect(byName(t, 'text-2')?.hex).toBe('#5C574E');
    expect(byName(t, 'primary')).toMatchObject({ hex: '#1F5E4B', role: 'primary', interactive: true });
    expect(byName(t, 'accent')).toMatchObject({ hex: '#E0763A', role: 'accent' });
    expect(byName(t, 'border')?.hex).toBe('#E4DDD0');
    expect(byName(t, 'primary-2')).toMatchObject({ hex: '#3FA37F', role: 'primary' });
  });

  it('drops light non-interactive colours but keeps interactive ones', () => {
    const base = [s('#FFFFFF', 'bg', 1_000_000)];
    const dropped = analyseColours([...base, s('#8A8A8A', 'bg', 1000)]);
    expect(dropped.map((x) => x.hex)).toEqual(['#FFFFFF']);
    const kept = analyseColours([...base, s('#8A8A8A', 'bg', 1000, true)]);
    expect(kept.map((x) => x.hex).sort()).toEqual(['#8A8A8A', '#FFFFFF']);
    expect(kept.find((x) => x.hex === '#8A8A8A')?.interactive).toBe(true);
  });

  it('ignores alpha 0 and unparsable, composites translucent over white', () => {
    const t = analyseColours([
      s('#FFFFFF', 'bg', 1_000_000),
      s('rgba(0,0,0,0)', 'bg', 5_000_000),
      s('transparent', 'bg', 5_000_000),
      s('not-a-colour', 'bg', 5_000_000),
      s('rgba(0,0,0,.04)', 'bg', 500_000),
    ]);
    expect(t.map((x) => x.hex).sort()).toEqual(['#F5F5F5', '#FFFFFF']);
  });

  it('handles sparse pages and empty input', () => {
    const t = analyseColours([s('#FFFFFF', 'bg', 1_000_000), s('#000000', 'text', 3000)]);
    expect(t.map((x) => x.role).sort()).toEqual(['surface', 'text']);
    expect(analyseColours([])).toEqual([]);
  });

  it('weights sum to at most 1 and names are unique', () => {
    const t = analyseColours(page());
    expect(t.reduce((a, x) => a + x.weight, 0)).toBeLessThanOrEqual(1 + 1e-9);
    expect(new Set(t.map((x) => x.name)).size).toBe(t.length);
  });
});
