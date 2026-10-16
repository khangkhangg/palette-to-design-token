import en from '../i18n/en.json';

type Dict = { [key: string]: string | Dict };

export const SUPPORTED_LANGS = ['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'] as const;
const RTL = new Set(['ar']);

// Only English ships in slices 1–3; other languages fall back to it.
const DICTS: Record<string, Dict> = { en };

let current = 'en';

function lookup(dict: Dict | undefined, key: string): string | undefined {
  let node: string | Dict | undefined = dict;
  for (const part of key.split('.')) {
    if (node === undefined || typeof node === 'string') return undefined;
    node = node[part];
  }
  return typeof node === 'string' ? node : undefined;
}

export function t(key: string, vars?: Record<string, string | number>): string {
  const s = lookup(DICTS[current], key) ?? lookup(DICTS.en, key) ?? key;
  if (!vars) return s;
  return s.replace(/\{(\w+)\}/g, (m, name: string) => (name in vars ? String(vars[name]) : m));
}

export function setLang(lang: string): void {
  current = lang;
  const root = document.documentElement;
  root.lang = lang;
  root.dir = RTL.has(lang) ? 'rtl' : 'ltr';
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
