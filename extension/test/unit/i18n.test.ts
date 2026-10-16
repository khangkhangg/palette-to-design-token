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
  it('falls back to English for a language without the key', () => {
    setLang('fr');
    expect(t('popup.copyHexList')).toBe('Copy hex list');
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
});

describe('setLang', () => {
  it('only English is available in this slice', () => {
    expect(AVAILABLE_LANGS).toEqual(['en']);
  });
  it('renders English, lang="en" dir="ltr", for a language without a dictionary', () => {
    setLang('es');
    expect(renderedLang()).toBe('en');
    expect(document.documentElement.lang).toBe('en');
    expect(document.documentElement.dir).toBe('ltr');
    setLang('ar');
    expect(document.documentElement.dir).toBe('ltr');
  });
  // Runs last: the injected dictionary stays registered for the rest of this file.
  it('sets rtl for Arabic once its dictionary is loaded', () => {
    addDictionary('ar', { popup: { copyHexList: 'نسخ' } });
    setLang('ar');
    expect(renderedLang()).toBe('ar');
    expect(document.documentElement.lang).toBe('ar');
    expect(document.documentElement.dir).toBe('rtl');
    expect(t('popup.copyHexList')).toBe('نسخ');
    expect(t('popup.retry')).toBe('Retry');
    setLang('en');
    expect(document.documentElement.dir).toBe('ltr');
  });
});
