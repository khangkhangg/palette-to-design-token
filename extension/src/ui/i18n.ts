import ar from '../i18n/ar.json';
import bn from '../i18n/bn.json';
import en from '../i18n/en.json';
import es from '../i18n/es.json';
import fr from '../i18n/fr.json';
import hi from '../i18n/hi.json';
import zhCN from '../i18n/zh-CN.json';

export type Dict = { [key: string]: string | Dict };

export const SUPPORTED_LANGS = ['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'] as const;
const RTL = new Set(['ar']);

// Every dictionary is bundled statically; AVAILABLE_LANGS is the Language menu order.
const DICTS: Record<string, Dict> = { en, 'zh-CN': zhCN, hi, es, ar, fr, bn };
export const AVAILABLE_LANGS: string[] = [...SUPPORTED_LANGS];

/** Each language's name in its own language, as the Language menu lists it. Deliberately not translated. */
export const LANG_NAMES: Record<string, string> = {
  en: 'English',
  'zh-CN': '中文',
  hi: 'हिन्दी',
  es: 'Español',
  ar: 'العربية',
  fr: 'Français',
  bn: 'বাংলা',
};

let rendered = 'en';

/** Registers a dictionary so setLang can render that language. */
export function addDictionary(lang: string, dict: Dict): void {
  DICTS[lang] = dict;
  if (!AVAILABLE_LANGS.includes(lang)) AVAILABLE_LANGS.push(lang);
}

/** The language whose strings are actually on screen. */
export function renderedLang(): string {
  return rendered;
}

function lookup(dict: Dict | undefined, key: string): string | undefined {
  let node: string | Dict | undefined = dict;
  for (const part of key.split('.')) {
    if (node === undefined || typeof node === 'string') return undefined;
    node = node[part];
  }
  return typeof node === 'string' ? node : undefined;
}

const RTL_CHAR = /[֐-ࣿיִ-﷿ﹰ-﻿]/;
const LETTER = /\p{L}/u;

/**
 * In an RTL language, wraps an interpolated value in a bidi isolate so "#F4F1EA" or a site name keeps its own
 * direction: RLI if it holds any RTL letter (also when that letter sits in a nested isolate, which a first-strong
 * isolate would skip), else LRI. Values without letters ("5.31", "127.0.0.1") stay bare so digits join their neighbours.
 */
function isolate(v: string): string {
  if (!RTL.has(rendered) || !LETTER.test(v)) return v;
  return `${RTL_CHAR.test(v) ? '⁧' : '⁦'}${v}⁩`;
}

export function t(key: string, vars?: Record<string, string | number>): string {
  const s = lookup(DICTS[rendered], key) ?? lookup(DICTS.en, key) ?? key;
  if (!vars) return s;
  return s.replace(/\{(\w+)\}/g, (m, name: string) => (name in vars ? isolate(String(vars[name])) : m));
}

/**
 * Picks `${key}_<CLDR category of n>` in the rendered language, falling back to its `${key}_other`.
 * A language without the key at all renders English, chosen by English plural rules.
 */
export function plural(key: string, n: number): string {
  const lang = lookup(DICTS[rendered], `${key}_other`) !== undefined ? rendered : 'en';
  const form = `${key}_${new Intl.PluralRules(lang).select(n)}`;
  const s = lookup(DICTS[lang], form) ?? lookup(DICTS[lang], `${key}_other`) ?? `${key}_other`;
  return s.replace(/\{n\}/g, String(n));
}

/** Renders `lang` if its dictionary is loaded, else English; sets <html lang/dir> to match. */
export function setLang(lang: string): void {
  rendered = AVAILABLE_LANGS.includes(lang) ? lang : 'en';
  const root = document.documentElement;
  root.lang = rendered;
  root.dir = RTL.has(rendered) ? 'rtl' : 'ltr';
}

function match(tag: string): string | undefined {
  if (!tag) return undefined;
  const lower = tag.toLowerCase();
  const exact = SUPPORTED_LANGS.find((l) => l.toLowerCase() === lower);
  if (exact) return exact;
  const prefix = lower.split('-')[0];
  return SUPPORTED_LANGS.find((l) => l.toLowerCase().split('-')[0] === prefix);
}

export function resolveLang(pref: string, browser: string): string {
  return match(pref) ?? match(browser) ?? 'en';
}
