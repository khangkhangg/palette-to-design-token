import { describe, it, expect } from 'vitest';
import { buildTokens } from '../../src/analyse/build';
import type { Samples } from '../../src/extract/collect';

const sparse = (): Samples => ({
  site: 'example.com',
  colours: [
    { css: 'rgb(255, 255, 255)', kind: 'bg', weight: 10000, interactive: false, nav: false },
    { css: 'rgb(0, 0, 0)', kind: 'text', weight: 100, interactive: false, nav: false },
  ],
  text: [{ family: 'Arial', sizePx: 16, weight: 400, lineHeightPx: null, chars: 100, heading: false, mono: false }],
  spacing: [], radius: [], shadows: [],
  pairs: [{ fg: 'rgb(0, 0, 0)', bg: '#FFFFFF', chars: 100, sizePx: 16, weight: 400, where: 'body text' }],
  truncated: false,
});

describe('buildTokens', () => {
  it('builds a valid Tokens from sparse samples', () => {
    const now = new Date('2026-10-16T00:00:00Z');
    const t = buildTokens(sparse(), now);
    expect(t.site).toBe('example.com');
    expect(t.scannedAt).toBe('2026-10-16T00:00:00.000Z');
    expect(t.spacing).toEqual({ base: null, scale: [] });
    expect(t.radius).toEqual([]);
    expect(t.shadows).toEqual([]);
    expect(t.colours.length).toBeGreaterThan(0);
    expect(t.type.length).toBeGreaterThan(0);
    expect(t.contrast).toHaveLength(1);
    expect(t.contrast[0].grade).toBe('AAA');
  });

  it('does not throw on fully empty samples', () => {
    const t = buildTokens({ site: '', colours: [], text: [], spacing: [], radius: [], shadows: [], pairs: [], truncated: true });
    expect(t.colours).toEqual([]);
  });
});
