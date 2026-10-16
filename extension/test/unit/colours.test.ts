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

  it('has no primary or accent when no chromatic colour is interactive or in nav', () => {
    const t = analyseColours([s('#FFFFFF', 'bg', 500_000), s('#A9CDF2', 'bg', 500_000), s('#1B1A17', 'text', 3000)]);
    expect(t.map((x) => x.role)).not.toContain('primary');
    expect(t.map((x) => x.role)).not.toContain('accent');
    expect(t.find((x) => x.hex === '#A9CDF2')?.role).toBe('other');
  });

  it('orders surfaces from the dominant background: dark to light on a dark page', () => {
    // T14 dark-app: the page background is the darkest and covers the most area, so it is "surface".
    const t = analyseColours([
      s('#21262D', 'bg', 200_000),
      s('#0D1117', 'bg', 1_000_000),
      s('#161B22', 'bg', 300_000),
      s('#E6EDF3', 'text', 3000),
    ]);
    expect(t.filter((x) => x.role === 'surface').map((x) => [x.name, x.hex])).toEqual([
      ['surface', '#0D1117'], ['surface-2', '#161B22'], ['surface-3', '#21262D'],
    ]);
  });

  it('picks primary by its share of link/button/nav use, not of the whole page', () => {
    // T14 gradient-hero: purple fills both buttons and colours the nav; pink is only three text links.
    // As a share of all page colour, a button's area is tiny next to the page background, so the
    // links' characters outweighed it and pink became primary.
    const t = analyseColours([
      s('#FFFFFF', 'bg', 1_500_000),
      s('#111827', 'text', 600),
      s('#DB2777', 'text', 57, true),
      s('#6D28D9', 'text', 25, true, true),
      s('#6D28D9', 'text', 17, true),
      s('#FFFFFF', 'text', 22, true),
      s('#FFFFFF', 'bg', 8000, true),
      s('#6D28D9', 'bg', 14_400, true),
    ]);
    expect(byName(t, 'primary')?.hex).toBe('#6D28D9');
    expect(byName(t, 'accent')?.hex).toBe('#DB2777');
  });

  it('does not let a lone nav logo fill outvote the link and button colour', () => {
    // Fill and border are rare in the action set: normalised per kind, one logo path would get a whole kind's share.
    const t = analyseColours([
      s('#FFFFFF', 'bg', 1_000_000),
      s('#111827', 'text', 2000),
      s('#FFFFFF', 'bg', 100_000, false, true),
      s('#374151', 'text', 100, true, true),
      s('#4F46E5', 'text', 60, true),
      s('#FFFFFF', 'text', 40, true),
      s('#4F46E5', 'bg', 4000, true),
      s('#E11D48', 'fill', 600, true, true),
    ]);
    expect(byName(t, 'primary')?.hex).toBe('#4F46E5');
  });

  it('makes a lone CTA background primary when it is the only action colour', () => {
    const t = analyseColours([
      s('#FFFFFF', 'bg', 1_000_000),
      s('#1A1A1A', 'text', 3000),
      s('#E0763A', 'bg', 6000, true),
      s('#FFFFFF', 'text', 12, true),
    ]);
    expect(byName(t, 'primary')?.hex).toBe('#E0763A');
  });

  it('weights sum to at most 1 and names are unique', () => {
    const t = analyseColours(page());
    expect(t.reduce((a, x) => a + x.weight, 0)).toBeLessThanOrEqual(1 + 1e-9);
    expect(new Set(t.map((x) => x.name)).size).toBe(t.length);
  });
});
