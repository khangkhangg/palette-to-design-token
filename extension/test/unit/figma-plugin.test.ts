import { createRequire } from 'node:module';
import { describe, expect, it } from 'vitest';
import { exportFile } from '../../src/export';
import { brewline } from './export/brewline.tokens';

/** The Figma plugin is plain JavaScript run by Figma; here it runs against a stand-in for the Figma API. */
const plugin = createRequire(import.meta.url)('../../../figma-plugin/code.js') as {
  hexToRgb(hex: string): { r: number; g: number; b: number } | null;
  weightStyle(w: number): string;
  readFile(text: string): any;
  apply(figma: any, plan: any): Promise<any>;
};

const PARTS = { parts: ['colours', 'typography', 'spacing', 'radius', 'shadows'] as ('colours' | 'typography' | 'spacing' | 'radius' | 'shadows')[] };

function fakeFigma(fonts: string[]) {
  const collections: any[] = [];
  const variables: any[] = [];
  const textStyles: any[] = [];
  const effectStyles: any[] = [];
  const figma = {
    variables: {
      getLocalVariableCollectionsAsync: async () => collections,
      getLocalVariablesAsync: async () => variables,
      createVariableCollection(name: string) {
        const c = { id: `c${collections.length}`, name, modes: [{ modeId: 'm0', name: 'Mode 1' }], renameMode(id: string, n: string) { this.modes.find((m: any) => m.modeId === id)!.name = n; } };
        collections.push(c);
        return c;
      },
      createVariable(name: string, collection: any, resolvedType: string) {
        const v = { name, variableCollectionId: collection.id, resolvedType, values: {} as Record<string, unknown>, setValueForMode(mode: string, value: unknown) { this.values[mode] = value; } };
        variables.push(v);
        return v;
      },
    },
    loadFontAsync: async (f: { family: string; style: string }) => { if (!fonts.includes(`${f.family} ${f.style}`)) throw new Error('missing font'); },
    getLocalTextStylesAsync: async () => textStyles,
    getLocalEffectStylesAsync: async () => effectStyles,
    createTextStyle() { const s: any = { name: '' }; textStyles.push(s); return s; },
    createEffectStyle() { const s: any = { name: '' }; effectStyles.push(s); return s; },
  };
  return { figma, collections, variables, textStyles, effectStyles };
}

describe('figma export for the plugin', () => {
  const file = JSON.parse(exportFile(brewline, 'figma', PARTS).text);
  it('carries the type scale as number variables', () => {
    expect(file.text.h1).toEqual({
      size: { $type: 'number', $value: brewline.type.find((x) => x.name === 'h1')!.sizePx },
      'line-height': { $type: 'number', $value: brewline.type.find((x) => x.name === 'h1')!.lineHeightPx },
      weight: { $type: 'number', $value: brewline.type.find((x) => x.name === 'h1')!.weight },
    });
  });
  it('carries text and shadow styles, with shadow colours as hex plus opacity', () => {
    const own = file.$extensions['com.vietnamcos.site-to-design-token'];
    expect(own.site).toBe(brewline.site);
    expect(own.textStyles.map((s: any) => s.name)).toEqual(brewline.type.map((x) => x.name));
    expect(own.textStyles[0]).toEqual(expect.objectContaining({ fontFamily: expect.any(String), fontSize: expect.any(Number), fontWeight: expect.any(Number) }));
    for (const s of own.shadowStyles) for (const l of s.layers) {
      expect(l.color).toMatch(/^#[0-9A-F]{6}$/);
      expect(l.opacity).toBeGreaterThan(0);
      expect(l.opacity).toBeLessThanOrEqual(1);
    }
    expect(own.shadowStyles.length).toBeGreaterThan(0);
  });
});

describe('figma plugin', () => {
  const text = exportFile(brewline, 'figma', PARTS).text;

  it('reads colours, numbers and strings as variables named by path', () => {
    const plan = plugin.readFile(text);
    const byName = Object.fromEntries(plan.variables.map((v: any) => [v.name, v]));
    expect(byName['color/primary']).toEqual({ name: 'color/primary', type: 'COLOR', value: { r: 0x1f / 255, g: 0x5e / 255, b: 0x4b / 255, a: 1 } });
    expect(byName['space/1']).toEqual({ name: 'space/1', type: 'FLOAT', value: 4 });
    expect(byName['text/h1/size'].type).toBe('FLOAT');
    expect(byName['font/body'].type).toBe('STRING');
    expect(plan.variables.some((v: any) => v.name.includes('$'))).toBe(false);
    expect(plan.skipped).toEqual([]);
  });

  it('creates one collection in Light mode, text styles and shadow styles; a second import updates, not duplicates', async () => {
    const f = fakeFigma(['Fraunces Semi Bold', 'Inter Regular']);
    const plan = plugin.readFile(text);
    const first = await plugin.apply(f.figma, plan);
    expect(f.collections.map((c) => c.name)).toEqual(['Site to Design Token · brewline.co']);
    expect(f.collections[0].modes[0].name).toBe('Light');
    expect(first.variables).toBe(plan.variables.length);
    expect(f.variables.find((v) => v.name === 'color/primary').values.m0).toEqual({ r: 0x1f / 255, g: 0x5e / 255, b: 0x4b / 255, a: 1 });

    const display = f.textStyles.find((s) => s.name === 'text/display');
    expect(display).toMatchObject({ fontName: { family: 'Fraunces', style: 'Semi Bold' }, fontSize: 48, lineHeight: { unit: 'PIXELS', value: 56 } });
    // DM Sans is not installed in this stand-in Figma: Inter is used, and the user is told.
    expect(f.textStyles.find((s) => s.name === 'text/body').fontName).toEqual({ family: 'Inter', style: 'Regular' });
    expect(first.notes.some((n: string) => n.includes('DM Sans is not available'))).toBe(true);
    expect(f.effectStyles.length).toBe(plan.shadowStyles.length);
    expect(f.effectStyles[0].effects[0]).toMatchObject({ type: 'DROP_SHADOW', visible: true, blendMode: 'NORMAL' });

    const counts = [f.variables.length, f.textStyles.length, f.effectStyles.length];
    await plugin.apply(f.figma, plugin.readFile(text.replace('#1F5E4B', '#7A3FC4')));
    expect([f.variables.length, f.textStyles.length, f.effectStyles.length]).toEqual(counts);
    expect(f.collections).toHaveLength(1);
    expect(f.variables.find((v) => v.name === 'color/primary').values.m0.r).toBeCloseTo(0x7a / 255);
  });

  it('leaves a variable of another type alone and says so', async () => {
    const f = fakeFigma(['Inter Regular']);
    await plugin.apply(f.figma, plugin.readFile(text));
    f.variables.find((v) => v.name === 'space/1').resolvedType = 'STRING';
    const again = await plugin.apply(f.figma, plugin.readFile(text));
    expect(again.notes).toContain('space/1: already exists with another type, left as it is');
  });

  it('refuses files that are not an export, in plain words', () => {
    expect(() => plugin.readFile('nope')).toThrow('That is not a JSON file.');
    expect(() => plugin.readFile('[]')).toThrow('That is not a Site to Design Token export.');
    expect(() => plugin.readFile('{"a":1}')).toThrow(/no tokens in it/);
  });

  it('maps weights and hex colours', () => {
    expect([400, 450, 600, 650, 1000].map(plugin.weightStyle)).toEqual(['Regular', 'Medium', 'Semi Bold', 'Bold', 'Black']);
    expect(plugin.hexToRgb('#fff')).toEqual({ r: 1, g: 1, b: 1 });
    expect(plugin.hexToRgb('rgb(0,0,0)')).toBeNull();
  });
});
