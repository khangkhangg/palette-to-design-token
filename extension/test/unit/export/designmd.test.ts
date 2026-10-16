import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { exportFile, type Part } from '../../../src/export';
import { EMPTY_TOKENS } from '../../../src/model/tokens';
import { brewline } from './brewline.tokens';

const ALL: Part[] = ['colours', 'typography', 'spacing', 'radius', 'shadows'];
const o = { parts: ALL };
const md = (t = brewline, opts = o) => exportFile(t, 'md', opts).text;

describe('DESIGN.md', () => {
  it('matches the golden', () => {
    const f = exportFile(brewline, 'md', o);
    expect(f.filename).toBe('DESIGN.md');
    expect(f.mime).toBe('text/markdown');
    expect(f.text).toBe(readFileSync(new URL('./golden/brewline.DESIGN.md', import.meta.url), 'utf8'));
  });
  it('writes the fail pair with its fix', () => {
    expect(md()).toContain('Do not put #FFFFFF text on #E0763A (2.9:1). Use #FFFFFF on #B4501A.');
  });
  it('writes the stored 2dp ratio, never rounding a fail up to 4.5', () => {
    const t = { ...brewline, contrast: [{ fg: '#5E7F5D', bg: '#FFFFFF', ratio: 4.49, large: false, uses: 1, where: 'other' as const, grade: 'fail' as const }] };
    expect(md(t)).toContain('(4.49:1)');
    expect(md(t)).not.toContain('4.5:1');
  });
  it('ends a fail pair without a fix at the ratio', () => {
    const t = { ...brewline, contrast: [{ fg: '#fff', bg: '#eee', ratio: 1.2, large: false, uses: 1, where: 'other' as const, grade: 'fail' as const }] };
    expect(md(t)).toContain('- Do not put #fff text on #eee (1.2:1).\n');
  });
  it('describes an image background and unknown pairs', () => {
    const t = {
      ...brewline,
      contrast: [
        { fg: '#fff', bg: 'none', ratio: 1.5, large: false, uses: 1, where: 'other' as const, grade: 'fail' as const },
        { fg: '#111', bg: 'none', ratio: null, large: false, uses: 1, where: 'other' as const, grade: 'unknown' as const },
      ],
    };
    const text = md(t);
    expect(text).toContain('- Do not put #fff text on an image or gradient (1.5:1).');
    expect(text).toContain('- Check manually: #111 text on an image or gradient.');
  });
  it('omits contrast when nothing fails, and empty sections', () => {
    const t = { ...brewline, contrast: [brewline.contrast[0]] };
    expect(md(t)).not.toContain('## Contrast rules');
    const e = md(EMPTY_TOKENS('x.com'));
    expect(e).toBe('# Design system — x.com\n\nUse only these tokens for UI.\nDo not invent new colours, fonts or spacing.\n');
  });
  it('respects parts but always keeps contrast rules', () => {
    const text = md(brewline, { parts: ['radius'] });
    expect(text).toContain('## Radius');
    expect(text).toContain('## Contrast rules');
    for (const h of ['Colour roles', 'Typography', 'Spacing', 'Shadows']) expect(text).not.toContain(`## ${h}`);
  });
  it('omits line-height when null', () => {
    const t = { ...brewline, type: [{ ...brewline.type[0], lineHeightPx: null }] };
    expect(md(t)).toContain('- display: Fraunces, serif 600, 48px\n');
  });
});
