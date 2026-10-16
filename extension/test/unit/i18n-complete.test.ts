// @vitest-environment jsdom
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { AVAILABLE_LANGS, LANG_NAMES, plural, setLang, t, type Dict } from '../../src/ui/i18n';

const DIR = resolve(import.meta.dirname, '../../src/i18n');
const LANGS = ['zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'];

function load(lang: string): Dict | undefined {
  const file = resolve(DIR, `${lang}.json`);
  return existsSync(file) ? (JSON.parse(readFileSync(file, 'utf8')) as Dict) : undefined;
}

function flatten(dict: Dict, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(dict)) {
    const key = prefix ? `${prefix}.${k}` : k;
    if (typeof v === 'string') out[key] = v;
    else Object.assign(out, flatten(v, key));
  }
  return out;
}

const EN = flatten(load('en')!);
/** Plural bases: en keys `<base>_other` that also have `<base>_one`. */
const PLURAL_BASES = Object.keys(EN)
  .filter((k) => k.endsWith('_other') && `${k.slice(0, -6)}_one` in EN)
  .map((k) => k.slice(0, -6));
const isPluralForm = (key: string) => PLURAL_BASES.some((b) => key.startsWith(`${b}_`));
const placeholders = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
/** Emoji, dingbats, geometric shapes, misc symbols and the emoji variation selector. */
const EMOJI = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{25A0}-\u{25FF}\u{2B00}-\u{2BFF}\u{FE0F}]/u;
/** Terms that stay in English (or are values) in every language: key -> exact value. */
const VERBATIM = ['app.name', 'unlock.keyPlaceholder', 'export.formats.md.label', 'export.formats.css.label',
  'export.formats.tailwind.label', 'export.formats.figma.label', 'export.formats.json.label', 'contrast.grade.AAA',
  'contrast.grade.AA', 'export.v4', 'export.v3', 'colours.lessThanOne', 'contrast.ratio', 'spacing.px'];

afterEach(() => setLang('en'));

describe.each(LANGS)('%s dictionary', (lang) => {
  const raw = load(lang);
  const dict = raw ? flatten(raw) : {};
  const categories = new Intl.PluralRules(lang).resolvedOptions().pluralCategories as string[];

  it('exists', () => {
    expect(raw, `src/i18n/${lang}.json`).toBeDefined();
  });

  it("has exactly en's keys, with the plural forms this language needs", () => {
    const expected = [
      ...Object.keys(EN).filter((k) => !isPluralForm(k)),
      ...PLURAL_BASES.flatMap((b) => categories.map((c) => `${b}_${c}`)),
    ].sort();
    expect(Object.keys(dict).sort()).toEqual(expected);
  });

  it('keeps every placeholder', () => {
    for (const [key, en] of Object.entries(EN)) {
      if (isPluralForm(key)) continue;
      expect(placeholders(dict[key] ?? ''), `${lang} ${key}`).toEqual(placeholders(en));
    }
    for (const base of PLURAL_BASES) {
      const want = placeholders(EN[`${base}_other`]);
      expect(placeholders(dict[`${base}_other`] ?? ''), `${lang} ${base}_other`).toEqual(want);
      for (const c of categories) {
        const got = placeholders(dict[`${base}_${c}`] ?? '');
        // A form for one exact number (Arabic zero/one/two: "لونان") may spell the number out instead of {n}.
        expect(got.every((p) => want.includes(p)), `${lang} ${base}_${c}`).toBe(true);
      }
    }
  });

  it('has no emoji, no HTML and no empty strings', () => {
    for (const [key, s] of Object.entries(dict)) {
      expect(EMOJI.test(s), `${lang} ${key}: ${s}`).toBe(false);
      expect(/<\/?[a-z]/i.test(s), `${lang} ${key}: ${s}`).toBe(false);
      expect(s.trim().length, `${lang} ${key}`).toBeGreaterThan(0);
    }
  });

  it('keeps product, format and value terms untranslated', () => {
    for (const key of VERBATIM) expect(dict[key], `${lang} ${key}`).toBe(EN[key]);
    expect(dict['unlock.paypal'], lang).toContain('PayPal');
    expect(dict['panel.exportFormats'], lang).toMatch(/CSS.*Tailwind.*Figma.*JSON.*DESIGN\.md/);
    expect(dict['unlock.refund'], lang).toContain('Pro');
    expect(dict['unlock.refund'], lang).toContain('1');
  });

  it('is registered and renders through t() and plural()', () => {
    expect(AVAILABLE_LANGS).toContain(lang);
    setLang(lang);
    expect(t('popup.copyHexList')).toBe(dict['popup.copyHexList']);
    const n = 5;
    expect(plural('header.colours', n)).toBe(
      dict[`header.colours_${new Intl.PluralRules(lang).select(n)}`].replace('{n}', String(n)),
    );
  });
});

describe('registry', () => {
  it('offers all seven languages in menu order, each with its native name', () => {
    expect(AVAILABLE_LANGS).toEqual(['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn']);
    expect(AVAILABLE_LANGS.map((l) => LANG_NAMES[l])).toEqual(
      ['English', '中文', 'हिन्दी', 'Español', 'العربية', 'Français', 'বাংলা'],
    );
  });

  it('picks every Arabic plural category', () => {
    setLang('ar');
    const ar = flatten(load('ar') ?? {});
    const forms = [0, 1, 2, 3, 11, 100].map((n) => plural('header.colours', n));
    expect(new Set(forms).size).toBe(6);
    expect(forms[3]).toBe(ar['header.colours_few'].replace('{n}', '3'));
    expect(forms[4]).toBe(ar['header.colours_many'].replace('{n}', '11'));
  });
});

describe('fonts', () => {
  const css = readFileSync(resolve(import.meta.dirname, '../../src/ui/theme.css'), 'utf8');
  it('falls back to system Noto faces for every script, bundling none of them', () => {
    expect(css).toContain(
      `--font-ui: 'Geist', 'Noto Sans', 'Noto Sans Arabic', 'Noto Sans Bengali', 'Noto Sans Devanagari', 'Noto Sans SC', system-ui, sans-serif;`,
    );
    expect(css).not.toMatch(/@font-face\s*{[^}]*Noto/);
  });
});
