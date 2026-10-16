import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { exportFile, slug, type Part } from '../../../src/export';
import { parseShadow } from '../../../src/export/shadow';
import { EMPTY_TOKENS } from '../../../src/model/tokens';
import { brewline } from './brewline.tokens';

const golden = (n: string) => readFileSync(new URL(`./golden/${n}`, import.meta.url), 'utf8');
const ALL: Part[] = ['colours', 'typography', 'spacing', 'radius', 'shadows'];
const o = { parts: ALL };

describe('goldens', () => {
  it('css', () => {
    const f = exportFile(brewline, 'css', o);
    expect(f.filename).toBe('tokens.css');
    expect(f.text).toBe(golden('brewline.css'));
  });
  it('css type variables use the Tailwind v4 names', () => {
    const css = exportFile(brewline, 'css', o).text;
    const tw = exportFile(brewline, 'tailwind', o).text;
    for (const l of ['--text-h1: 24px;', '--text-h1--line-height: 32px;', '--text-h1--font-weight: 600;']) {
      expect(css).toContain(`  ${l}\n`);
      expect(tw).toContain(`  ${l}\n`);
    }
    expect(css).not.toMatch(/--text-[a-z0-9]+-(size|weight|line-height):/);
  });
  it('tailwind v4 (default)', () => {
    const f = exportFile(brewline, 'tailwind', o);
    expect(f.filename).toBe('tailwind.css');
    expect(f.text).toBe(golden('brewline.tw4.css'));
    expect(exportFile(brewline, 'tailwind', { ...o, tailwind: 4 }).text).toBe(f.text);
  });
  it('tailwind v3', () => {
    const f = exportFile(brewline, 'tailwind', { ...o, tailwind: 3 });
    expect(f.filename).toBe('tailwind.config.js');
    expect(f.text).toBe(golden('brewline.tw3.js'));
  });
  it('dtcg json', () => {
    const f = exportFile(brewline, 'json', o);
    expect(f.filename).toBe('tokens.json');
    expect(f.mime).toBe('application/json');
    expect(f.text).toBe(golden('brewline.tokens.json'));
  });
  it('figma json', () => {
    const f = exportFile(brewline, 'figma', o);
    expect(f.filename).toBe('figma-variables.json');
    expect(f.text).toBe(golden('brewline.figma.json'));
  });
});

const formats = [
  ['css', {}],
  ['tailwind', { tailwind: 4 }],
  ['tailwind', { tailwind: 3 }],
  ['json', {}],
  ['figma', {}],
] as const;

describe('parts and empty sections', () => {
  it.each(formats)('colours only (%s %j)', (fmt, extra) => {
    const t = exportFile(brewline, fmt, { parts: ['colours'], ...extra }).text;
    expect(t).toContain('#1F5E4B');
    expect(t).not.toMatch(
      /(--font|--text|--space|--spacing|--radius|--shadow|fontFamily|fontSize|borderRadius|boxShadow|"\$type": "(fontFamily|typography|dimension|shadow|number|string)")/,
    );
  });
  it('no shadows → no shadow section anywhere', () => {
    const t = { ...brewline, shadows: [] };
    for (const [fmt, extra] of formats) {
      const text = exportFile(t, fmt, { parts: ALL, ...extra }).text;
      expect(text).not.toMatch(/shadow/i);
    }
  });
  it('empty tokens produce minimal valid files', () => {
    expect(exportFile(EMPTY_TOKENS('x'), 'css', o).text).toBe(':root {\n}\n');
    expect(exportFile(EMPTY_TOKENS('x'), 'tailwind', o).text).toBe('@import "tailwindcss";\n\n@theme {\n}\n');
    expect(JSON.parse(exportFile(EMPTY_TOKENS('x'), 'json', o).text)).toEqual({});
  });
  it('omits line-height when null', () => {
    const t = { ...brewline, type: [{ ...brewline.type[2], lineHeightPx: null }] };
    expect(exportFile(t, 'css', o).text).not.toContain('line-height');
    expect(exportFile(t, 'tailwind', o).text).not.toContain('line-height');
    expect(exportFile(t, 'tailwind', { ...o, tailwind: 3 }).text).not.toContain('lineHeight');
    const j = JSON.parse(exportFile(t, 'json', o).text);
    expect(j.text.body.$value).not.toHaveProperty('lineHeight');
  });
  it('tailwind v4 omits the base comment when base is null', () => {
    const t = { ...brewline, spacing: { base: null, scale: [5, 7] } };
    const text = exportFile(t, 'tailwind', o).text;
    expect(text).not.toMatch(/--spacing:/);
    expect(text).not.toContain('detected base');
    expect(text).toContain('--spacing-space-1: 5px;');
  });
  it('tailwind v4 never overrides the numeric spacing scale (R17)', () => {
    const text = exportFile(brewline, 'tailwind', o).text;
    expect(text).not.toMatch(/--spacing-\d/);
    // --spacing: only inside a comment
    const uncommented = text.replace(/\/\*[\s\S]*?\*\//g, '');
    expect(uncommented).not.toMatch(/--spacing:/);
    expect(text).toContain('/* detected base: 4px — set --spacing: 4px; to adopt it */');
    expect(text).toContain('--spacing-space-1: 4px;');
    expect(text).toMatch(/\/\*[^*]*space-[^*]*numeric[^*]*\*\//);
  });
  it('tailwind v3 spacing keys are space-n, never numeric (R17)', () => {
    const text = exportFile(brewline, 'tailwind', { ...o, tailwind: 3 }).text;
    const spacing = /spacing: \{([\s\S]*?)\n {6}\},/.exec(text)![1];
    expect(spacing).not.toMatch(/^\s*"?\d+"?:/m);
    expect(spacing).toContain('"space-1": "4px",');
    expect(text).toMatch(/\/\/[^\n]*space-[^\n]*numeric/);
  });
});

describe('font families', () => {
  const fam = '"Helvetica Neue", Arial, sans-serif';
  const t = {
    ...brewline,
    fonts: [{ name: 'body' as const, family: fam }],
    type: [{ ...brewline.type[2], family: fam }],
  };
  it('css verbatim', () => {
    expect(exportFile(t, 'css', o).text).toContain(`--font-body: ${fam};`);
  });
  it('v3 array', () => {
    expect(exportFile(t, 'tailwind', { ...o, tailwind: 3 }).text).toContain(
      'body: ["Helvetica Neue", "Arial", "sans-serif"],',
    );
  });
  it('json round-trips', () => {
    const j = JSON.parse(exportFile(t, 'json', o).text);
    expect(j.font.body.$value).toEqual(['Helvetica Neue', 'Arial', 'sans-serif']);
    expect(j.text.body.$value.fontFamily).toEqual(['Helvetica Neue', 'Arial', 'sans-serif']);
    const f = JSON.parse(exportFile(t, 'figma', o).text);
    expect(f.font.body.$value).toBe(fam);
  });
});

describe('dtcg', () => {
  const missingType = (n: any, path = ''): string[] =>
    n && typeof n === 'object' && '$value' in n
      ? n.$type ? [] : [path]
      : Object.entries(n).flatMap(([k, v]) => missingType(v, `${path}/${k}`));
  it('every leaf has $type', () => {
    const j = JSON.parse(exportFile(brewline, 'json', o).text);
    expect(missingType(j)).toEqual([]);
    expect(j.color.primary).toEqual({ $type: 'color', $value: '#1F5E4B' });
    expect(j.space['2']).toEqual({ $type: 'dimension', $value: { value: 8, unit: 'px' } });
    expect(j.text.h1.$value.fontWeight).toBe(600);
  });
  it('multi-layer shadow is an array; inset and unparseable are omitted from json/figma but kept in css', () => {
    const t = {
      ...brewline,
      shadows: ['0 1px 2px #000, 0 4px 8px 1px rgba(0,0,0,.2)', 'inset 0 1px 0 #fff', 'weird thing'],
    };
    // named by size (blur, then y): inset 0-blur is sm, the 8px-blur pair md, unmeasurable last
    const j = JSON.parse(exportFile(t, 'json', o).text);
    expect(Object.keys(j.shadow)).toEqual(['md']);
    expect(j.shadow.md.$value).toHaveLength(2);
    expect(j.shadow.md.$value[1]).toEqual({
      color: 'rgba(0,0,0,.2)',
      offsetX: { value: 0, unit: 'px' },
      offsetY: { value: 4, unit: 'px' },
      blur: { value: 8, unit: 'px' },
      spread: { value: 1, unit: 'px' },
    });
    expect(exportFile(t, 'figma', o).text).not.toMatch(/shadow|inset/);
    const css = exportFile(t, 'css', o).text;
    expect(css).toContain('--shadow-sm: inset 0 1px 0 #fff;');
    expect(css).toContain('--shadow-lg: weird thing;');
    expect(exportFile(t, 'tailwind', o).text).toContain('--shadow-sm: inset 0 1px 0 #fff;');
  });
  it('names shadows by size, not by how often they are used', () => {
    const t = { ...brewline, shadows: ['0 20px 40px #000', '0 1px 2px #000', '0 8px 16px #000', '0 2px 16px #000'] };
    const css = exportFile(t, 'css', o).text;
    expect(css).toContain('--shadow-sm: 0 1px 2px #000;');
    expect(css).toContain('--shadow-md: 0 2px 16px #000;');
    expect(css).toContain('--shadow-lg: 0 8px 16px #000;');
    expect(css).toContain('--shadow-xl: 0 20px 40px #000;');
  });
  it('no parseable shadows → no shadow group', () => {
    const t = { ...brewline, shadows: ['inset 0 1px #fff'] };
    expect(JSON.parse(exportFile(t, 'json', o).text)).not.toHaveProperty('shadow');
  });
});

describe('contrast fix tokens (R16)', () => {
  it('emit --color-accent-aa in every colour export', () => {
    expect(exportFile(brewline, 'css', o).text).toContain('  --color-accent-aa: #B4501A;\n');
    expect(exportFile(brewline, 'tailwind', o).text).toContain('  --color-accent-aa: #B4501A;\n');
    expect(exportFile(brewline, 'tailwind', { ...o, tailwind: 3 }).text).toContain('"accent-aa": "#B4501A",');
    expect(JSON.parse(exportFile(brewline, 'json', o).text).color['accent-aa']).toEqual({ $type: 'color', $value: '#B4501A' });
    expect(JSON.parse(exportFile(brewline, 'figma', o).text).color['accent-aa']).toEqual({ $type: 'color', $value: '#B4501A' });
  });
  it('none when no fail pair has a fix, or colours are not exported', () => {
    const t = { ...brewline, contrast: [brewline.contrast[0]] };
    expect(exportFile(t, 'css', o).text).not.toContain('-aa');
    expect(exportFile(brewline, 'css', { parts: ['radius'] }).text).not.toContain('-aa');
  });
  it('dedupes a repeated fix and suffixes a second fix for the same role', () => {
    const fail = (fg: string, bg: string, fix: { fg: string; bg: string }) =>
      ({ fg, bg, ratio: 3, large: false, uses: 1, where: 'other' as const, grade: 'fail' as const, fix: { ...fix, ratio: 4.6 } });
    const t = {
      ...brewline,
      contrast: [
        fail('#FFFFFF', '#2E7A63', { fg: '#FFFFFF', bg: '#1F5E4A' }), // primary-2 -> primary-aa
        fail('#FFFDF8', '#2E7A63', { fg: '#FFFDF8', bg: '#1F5E4A' }), // same fix colour: reused
        fail('#FFFFFF', '#1F5E4B', { fg: '#FFFFFF', bg: '#123A2E' }), // primary -> primary-aa-2
        fail('#FFFFFF', '#E4DDD0', { fg: '#FFFFFF', bg: '#1B1A17' }), // fix is already --color-text: no new token
      ],
    };
    const css = exportFile(t, 'css', o).text;
    expect(css.match(/--color-[\w-]*aa[\w-]*: #[0-9A-F]+;/g)).toEqual([
      '--color-primary-aa: #1F5E4A;',
      '--color-primary-aa-2: #123A2E;',
    ]);
  });
});

describe('hostile font-family values', () => {
  const evil = 'Inter\n}\n:root { --x: 1; }\u0000' + 'B'.repeat(80) + ', "a;b", c, d, e, f, g, h';
  const t = { ...brewline, fonts: [{ name: 'body' as const, family: evil }], type: [{ ...brewline.type[2], family: evil }] };
  it.each(formats)('stay one bounded, inert value (%s %j)', (fmt, extra) => {
    const text = exportFile(t, fmt, { parts: ALL, ...extra }).text;
    expect(text).not.toMatch(/^\s*--x/m); // no injected declaration on its own line
    expect(text).not.toMatch(/\{\s*--x/);
    expect(text).not.toContain('\u0000');
    expect(text).not.toContain('B'.repeat(65));
    expect(text).not.toMatch(/\bh\b"?[,\]]/);
  });
  it('css keeps one declaration per line', () => {
    const line = exportFile(t, 'css', o).text.split('\n').find((l) => l.includes('--font-body'))!;
    expect(line).toMatch(/^ {2}--font-body: [^;{}]+;$/);
  });
});

describe('figma', () => {
  it('numbers, strings and a single Light mode', () => {
    const j = JSON.parse(exportFile(brewline, 'figma', o).text);
    expect(j.space['1']).toEqual({ $type: 'number', $value: 4 });
    expect(j.radius.full).toEqual({ $type: 'number', $value: 9999 });
    expect(j.$extensions['com.figma'].modes).toEqual(['Light']);
  });
});

describe('index', () => {
  it('slug', () => {
    expect(slug('brewline.co')).toBe('brewline-co');
    expect(slug('Sub_Domain.Example.com/x?y')).toBe('sub-domain-example-com-x-y');
  });
  it('radius naming caps at four sizes plus full', () => {
    const t = { ...brewline, radius: [2, 4, 6, 8, 9999], shadows: [] };
    const text = exportFile(t, 'css', o).text;
    expect(text).toContain('--radius-xl: 8px;');
    expect(text).toContain('--radius-full: 9999px;');
  });
});

describe('parseShadow', () => {
  it('parses colour-first and optional blur/spread', () => {
    expect(parseShadow('red 2px 3px')).toEqual([{ color: 'red', offsetX: 2, offsetY: 3, blur: 0, spread: 0 }]);
    expect(parseShadow('0 0 0 1px #abc')).toEqual([{ color: '#abc', offsetX: 0, offsetY: 0, blur: 0, spread: 1 }]);
  });
  it('rejects inset, rem units and garbage', () => {
    expect(parseShadow('inset 0 1px #000')).toBeNull();
    expect(parseShadow('0 1rem 2px #000')).toBeNull();
    expect(parseShadow('none')).toBeNull();
    expect(parseShadow('0 1px')).toBeNull();
  });
});
