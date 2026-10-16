import en from '../i18n/en.json';

export type Dict = { [key: string]: string | Dict };

export const SUPPORTED_LANGS = ['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'] as const;
const RTL = new Set(['ar']);

// Only English ships in slices 1–3; other languages render as English until their dictionary is added.
const DICTS: Record<string, Dict> = { en };
export const AVAILABLE_LANGS: string[] = ['en'];

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

export function t(key: string, vars?: Record<string, string | number>): string {
  const s = lookup(DICTS[rendered], key) ?? lookup(DICTS.en, key) ?? key;
  if (!vars) return s;
  return s.replace(/\{(\w+)\}/g, (m, name: string) => (name in vars ? String(vars[name]) : m));
}

/** Picks `${key}_one` / `${key}_other` (CLDR category for n), falling back to `_other`. */
export function plural(key: string, n: number): string {
  const exact = `${key}_${new Intl.PluralRules(rendered).select(n)}`;
  const known = (lookup(DICTS[rendered], exact) ?? lookup(DICTS.en, exact)) !== undefined;
  return t(known ? exact : `${key}_other`, { n });
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
