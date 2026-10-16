import { strFromU8, unzipSync } from 'fflate';
import { describe, expect, it } from 'vitest';
import { exportAll, exportFile, slug, type Part } from '../../../src/export';
import { brewline } from './brewline.tokens';

const ALL: Part[] = ['colours', 'typography', 'spacing', 'radius', 'shadows'];
const o = { parts: ALL };

describe('exportAll', () => {
  it('bundles exactly the five files, matching exportFile', async () => {
    const z = await exportAll(brewline, o);
    expect(z.filename).toBe('brewline-co-tokens.zip');
    expect(z.mime).toBe('application/zip');
    const files = unzipSync(z.bytes);
    const expected = (['css', 'tailwind', 'json', 'figma', 'md'] as const).map((f) => exportFile(brewline, f, { ...o, tailwind: 4 }));
    expect(Object.keys(files).sort()).toEqual(expected.map((e) => e.filename).sort());
    for (const e of expected) expect(strFromU8(files[e.filename])).toBe(e.text);
  });
  it('honours tailwind 3', async () => {
    const files = unzipSync((await exportAll(brewline, { ...o, tailwind: 3 })).bytes);
    expect(Object.keys(files)).toContain('tailwind.config.js');
  });
  it('slugs the site name, with a fallback', async () => {
    expect((await exportAll({ ...brewline, site: 'My Site (beta)' }, o)).filename).toBe('my-site-beta-tokens.zip');
    expect((await exportAll({ ...brewline, site: '!!!' }, o)).filename).toBe('site-tokens.zip');
    expect(slug('!!!')).toBe('site');
  });
});
