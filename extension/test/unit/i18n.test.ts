// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { resolveLang, setLang, t } from '../../src/ui/i18n';

afterEach(() => setLang('en'));

describe('t', () => {
  it('returns the English string for a key', () => {
    expect(t('popup.copyHexList')).toBe('Copy hex list');
  });
  it('interpolates variables', () => {
    expect(t('header.counts', { colours: 14, fonts: 3 })).toBe('14 colours · 3 fonts');
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
  it('sets rtl for Arabic', () => {
    setLang('ar');
    expect(document.documentElement.dir).toBe('rtl');
    expect(document.documentElement.lang).toBe('ar');
  });
  it('sets ltr otherwise', () => {
    setLang('ar');
    setLang('en');
    expect(document.documentElement.dir).toBe('ltr');
  });
});
