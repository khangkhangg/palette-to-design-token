// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { addDictionary, AVAILABLE_LANGS, plural, renderedLang, resolveLang, setLang, t } from '../../src/ui/i18n';

afterEach(() => setLang('en'));

describe('t', () => {
  it('returns the English string for a key', () => {
    expect(t('popup.copyHexList')).toBe('Copy hex list');
  });
  it('interpolates variables', () => {
    expect(t('header.counts', { colours: plural('header.colours', 14), fonts: plural('header.fonts', 3) }))
      .toBe('14 colours · 3 fonts');
  });
  it('pluralises counts', () => {
    expect(t('header.counts', { colours: plural('header.colours', 1), fonts: plural('header.fonts', 1) }))
      .toBe('1 colour · 1 font');
    expect(plural('header.fonts', 0)).toBe('0 fonts');
  });
  it('returns the key when it is unknown', () => {
    expect(t('nope.missing')).toBe('nope.missing');
  });
});

describe('resolveLang', () => {
  it('uses the browser language prefix when no preference is set', () => {
    expect(resolveLang('', 'es-MX')).toBe('es');
  });
  it('falls back to English for an unsupported browser language', () => {
    expect(resolveLang('', 'de')).toBe('en');
  });
  it('prefers the saved preference', () => {
    expect(resolveLang('ar', 'en')).toBe('ar');
  });
  it('keeps region-specific supported languages', () => {
    expect(resolveLang('', 'zh-CN')).toBe('zh-CN');
  });
  it('maps other Chinese tags to Simplified Chinese', () => {
    expect(resolveLang('', 'zh')).toBe('zh-CN');
  });
});

describe('setLang', () => {
  it('ships all seven languages', () => {
    expect(AVAILABLE_LANGS).toEqual(['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn']);
  });
  it('sets lang and dir: rtl for Arabic, ltr otherwise', () => {
    setLang('ar');
    expect(renderedLang()).toBe('ar');
    expect(document.documentElement.lang).toBe('ar');
    expect(document.documentElement.dir).toBe('rtl');
    setLang('hi');
    expect(document.documentElement.lang).toBe('hi');
    expect(document.documentElement.dir).toBe('ltr');
  });
  it('renders English, lang="en" dir="ltr", for a language without a dictionary', () => {
    setLang('de');
    expect(renderedLang()).toBe('en');
    expect(document.documentElement.lang).toBe('en');
    expect(document.documentElement.dir).toBe('ltr');
  });
});

// Runs last: the injected dictionaries stay registered for the rest of this file.
describe('partial dictionaries', () => {
  it('fall back to English for a missing key', () => {
    addDictionary('ru', { popup: { copyHexList: 'Копировать' } });
    setLang('ru');
    expect(t('popup.copyHexList')).toBe('Копировать');
    expect(t('popup.retry')).toBe('Retry');
  });
  it("plural uses the language's _other when its category form is missing", () => {
    // Russian 1 is "one", 3 is "few": neither form is given, so both use _other, not English "_one".
    addDictionary('ru', { header: { colours_other: '{n} цв.' } });
    setLang('ru');
    expect(plural('header.colours', 1)).toBe('1 цв.');
    expect(plural('header.colours', 3)).toBe('3 цв.');
  });
  it('plural uses English forms when the language lacks the key entirely', () => {
    addDictionary('ru', {});
    setLang('ru');
    expect(plural('header.colours', 1)).toBe('1 colour');
    expect(plural('header.colours', 2)).toBe('2 colours');
  });
});

describe('bidi isolation', () => {
  const LRI = '⁦';
  const RLI = '⁧';
  const PDI = '⁩';

  it('isolates interpolated left-to-right values in Arabic so "#F4F1EA" keeps its # on the left', () => {
    setLang('ar');
    expect(t('contrast.pairColours', { fg: '#5B6470', bg: '#F4F1EA' }))
      .toBe(`${LRI}#5B6470${PDI} على ${LRI}#F4F1EA${PDI}`);
  });
  it('isolates Arabic values right-to-left, also when they only nest isolated values', () => {
    setLang('ar');
    const colours = plural('header.colours', 7);
    const fonts = plural('header.fonts', 2);
    expect([colours, fonts]).toEqual(['7 ألوان', 'خطّان']);
    const counts = t('header.counts', { colours, fonts });
    expect(counts).toBe(`${RLI}7 ألوان${PDI} · ${RLI}خطّان${PDI}`);
    expect(t('header.subtitle', { site: 'brewline.co', counts }))
      .toBe(`${LRI}brewline.co${PDI} · ${RLI}${counts}${PDI}`);
  });
  it('leaves values without letters alone, so "5.31:1" stays one number run', () => {
    setLang('ar');
    expect(t('contrast.ratio', { ratio: '5.31' })).toBe('5.31:1');
    expect(t('spacing.base', { n: 8 })).toBe('الأساس 8px');
  });
  it('leaves left-to-right languages untouched', () => {
    setLang('fr');
    expect(t('contrast.pairColours', { fg: '#5B6470', bg: '#F4F1EA' })).toBe('#5B6470 sur #F4F1EA');
  });
});
