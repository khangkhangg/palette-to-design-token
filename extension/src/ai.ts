/**
 * AI insights client: what the extension sends (spec 5.1, nothing more), the analyse call with its 45 s budget,
 * and the last result per site kept in this browser (shown again without spending an analysis).
 */
import { API_BASE } from './config';
import { currentAiLicense, storeAiUsage, type AiUsage } from './license';
import type { Role, Tokens } from './model/tokens';

export interface AiSite {
  name: string; domain: string; url: string; why: string;
  relation: 'competitor' | 'similar'; source: 'web' | 'ai';
}
export interface AiResult {
  sector: string;
  tags: string[];
  verdict: 'behind' | 'on_par' | 'competitive';
  summary: string;
  colours: { hex: string; title: string; meaning: string }[];
  fit: { level: 'strong' | 'ok' | 'weak'; reason: string };
  works: string[];
  improve: string[];
  sites: AiSite[];
  search_unavailable?: boolean;
}
export interface DesignSummary {
  colours: { role: Role; hex: string }[];
  fonts: string[];
  type_sizes: number[];
  spacing_base: number | null;
  radius: number[];
  contrast: { pass: number; fail: number; failing: { fg: string; bg: string; ratio: number | null }[] };
}
export type AnalyseReason =
  | 'not_ai' | 'ended' | 'quota' | 'rate_limited' | 'unavailable' | 'network' | 'timeout'
  /** Refused before sending: not a public http(s) page (localhost, IPs, .local …). */
  | 'unsupported'
  /** The server refused the request (400 / 413). */
  | 'bad_request'
  /** A 200 whose result does not fit the schema (unknown verdict or fit level, no usage). */
  | 'invalid'
  /** The caller aborted (Cancel). */
  | 'cancelled';
export type AnalyseOutcome =
  | { ok: true; result: AiResult; cached: boolean; usage: AiUsage }
  | { ok: false; reason: AnalyseReason; endedOn?: string; resetsOn?: string };
export interface StoredResult { result: AiResult; usage: AiUsage | null; analysedAt: string; lang: string; cached: boolean }

export const ANALYSE_TIMEOUT_MS = 45_000;
const RESULTS_KEY = 'aiResults';
const MAX_RESULTS = 30;
const TITLE_MAX = 200;
const DESCRIPTION_MAX = 300;
const PATH_MAX = 300;
const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;
const PASS = new Set(['AAA', 'AA', 'AA-large']);

/** The design summary the server sees: role colours, families, type sizes, spacing base, radius and contrast counts. */
export function designSummary(t: Tokens): DesignSummary {
  const fonts: string[] = [];
  for (const f of t.fonts) {
    const family = f.family.split(',')[0].replace(/["']/g, '').trim();
    if (family && !fonts.includes(family) && fonts.length < 3) fonts.push(family);
  }
  const sizes = [...new Set(t.type.map((x) => Math.round(x.sizePx * 100) / 100))].filter((n) => n > 0).sort((a, b) => b - a);
  const failing = t.contrast
    .filter((p) => p.grade === 'fail' && HEX.test(p.fg) && HEX.test(p.bg))
    .slice(0, 6)
    .map((p) => ({ fg: p.fg, bg: p.bg, ratio: p.ratio }));
  return {
    colours: t.colours.filter((c) => HEX.test(c.hex)).slice(0, 10).map((c) => ({ role: c.role, hex: c.hex })),
    fonts,
    type_sizes: sizes.slice(0, 12),
    spacing_base: t.spacing.base,
    radius: t.radius.slice(0, 6),
    contrast: {
      pass: t.contrast.filter((p) => PASS.has(p.grade)).length,
      fail: t.contrast.filter((p) => p.grade === 'fail').length,
      failing,
    },
  };
}

/** Lowercase, without a leading "www.": the server's cache key, and how results are kept here. */
export function siteHost(host: string): string {
  return host.toLowerCase().replace(/\.$/, '').replace(/^www\./, '');
}

/**
 * A public http(s) page as the server accepts it: origin + safe first path segment (no userinfo, query, fragment or
 * deeper path) and its host. Null for anything the server refuses: other schemes, IP literals, single-label and local
 * hosts (localhost, .local, .localhost, .internal, .test), numeric final labels, or a path over 300 characters.
 */
export function publicPage(raw: string): { url: string; host: string } | null {
  let u: URL;
  try { u = new URL(raw); } catch { return null; }
  if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
  const hostname = u.hostname.toLowerCase().replace(/\.$/, '');
  if (hostname === '' || hostname.startsWith('[') || /^\d+(\.\d+){3}$/.test(hostname)) return null;
  const host = siteHost(hostname);
  if (!host.includes('.') || !/^[a-z0-9._-]+$/.test(host) || /(^|\.)\.|\.\./.test(host)) return null;
  if (/\.(\d+|0x[0-9a-f]+)$/.test(host)) return null;
  if (host === 'localhost' || /\.(local|localhost|internal|test)$/.test(host)) return null;
  const path = u.pathname || '/';
  if (path.length > PATH_MAX) return null;
  return { url: `${u.protocol}//${hostname}${u.port ? `:${u.port}` : ''}${safePath(path)}`, host };
}

/**
 * Ruling A14: only the first path segment is sent, and only when it is at most 24 characters with no digits and no @
 * ("/shop", "/pricing"); any other path becomes "/". The server applies the same rule.
 */
function safePath(path: string): string {
  const first = path.split('/')[1] ?? '';
  return /^[^/\d@]{1,24}$/.test(first) ? `/${first}` : '/';
}

const str = (v: unknown, max = 600): string => (typeof v === 'string' ? v.trim().slice(0, max) : '');
const strs = (v: unknown, cap: number): string[] =>
  (Array.isArray(v) ? v : []).map((x) => str(x, 200)).filter((x) => x !== '').slice(0, cap);
const oneOf = <T extends string>(v: unknown, allowed: readonly T[], fallback: T): T =>
  (allowed as readonly unknown[]).includes(v) ? (v as T) : fallback;
const VERDICTS = ['behind', 'on_par', 'competitive'] as const;
const FIT_LEVELS = ['strong', 'ok', 'weak'] as const;
const DOMAIN = /^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/;

/** The answer reduced to the schema; links are rebuilt from the domain, so only https://<domain>/ is ever opened. */
function cleanResult(raw: unknown): AiResult | null {
  if (!raw || typeof raw !== 'object') return null;
  const r = raw as Record<string, unknown>;
  const fit = (r.fit ?? {}) as Record<string, unknown>;
  // The verdict and fit level are shown as the answer itself: an unknown one makes the result invalid, never a guess.
  const verdict = VERDICTS.find((v) => v === r.verdict);
  const level = FIT_LEVELS.find((v) => v === fit.level);
  if (!verdict || !level) return null;
  const sites: AiSite[] = [];
  for (const s of Array.isArray(r.sites) ? r.sites : []) {
    const o = (s ?? {}) as Record<string, unknown>;
    const domain = str(o.domain, 190).toLowerCase();
    if (!DOMAIN.test(domain) || sites.length >= 6) continue;
    sites.push({
      name: str(o.name, 120) || domain, domain, url: `https://${domain}/`, why: str(o.why, 200),
      relation: oneOf(o.relation, ['competitor', 'similar'] as const, 'similar'),
      source: oneOf(o.source, ['web', 'ai'] as const, 'ai'),
    });
  }
  const colours = (Array.isArray(r.colours) ? r.colours : [])
    .map((c) => (c ?? {}) as Record<string, unknown>)
    .filter((c) => typeof c.hex === 'string' && HEX.test(c.hex))
    .slice(0, 4)
    .map((c) => ({ hex: c.hex as string, title: str(c.title, 120), meaning: str(c.meaning, 400) }));
  const out: AiResult = {
    sector: str(r.sector, 120),
    tags: strs(r.tags, 4),
    verdict,
    summary: str(r.summary, 800),
    colours,
    fit: { level, reason: str(fit.reason, 300) },
    works: strs(r.works, 3),
    improve: strs(r.improve, 3),
    sites,
  };
  if (typeof r.search_unavailable === 'boolean') out.search_unavailable = r.search_unavailable;
  return out;
}

function parseUsage(raw: unknown): AiUsage | null {
  const u = raw as { used?: unknown; limit?: unknown; resets_on?: unknown } | null;
  return u && typeof u.used === 'number' && typeof u.limit === 'number' && typeof u.resets_on === 'string'
    ? { used: u.used, limit: u.limit, resetsOn: u.resets_on }
    : null;
}

type Entry = StoredResult & { host: string };

async function readResults(): Promise<Entry[]> {
  const { [RESULTS_KEY]: list } = await chrome.storage.local.get(RESULTS_KEY);
  return Array.isArray(list) ? (list as Entry[]).filter((e) => e && typeof e.host === 'string' && e.result) : [];
}

async function keepResult(host: string, entry: StoredResult): Promise<void> {
  const list = (await readResults()).filter((e) => e.host !== host);
  await chrome.storage.local.set({ [RESULTS_KEY]: [{ host, ...entry }, ...list].slice(0, MAX_RESULTS) });
}

/** The last result kept for this site (any "www." or case), marked as most recently used; null if none. */
export async function storedResult(host: string): Promise<StoredResult | null> {
  const key = siteHost(host);
  const list = await readResults();
  const i = list.findIndex((e) => e.host === key);
  if (i < 0) return null;
  const { host: _host, ...entry } = list[i];
  if (i > 0) await chrome.storage.local.set({ [RESULTS_KEY]: [list[i], ...list.slice(0, i), ...list.slice(i + 1)] });
  return entry;
}

/**
 * Runs one analysis. Spends an analysis on the server unless the answer is a shared cached one; a timeout,
 * a failure or an invalid answer spends none. The result and the usage it reports are kept locally.
 */
export async function analyse(
  tokens: Tokens, page: { url: string; title: string; description: string }, lang: string, signal?: AbortSignal,
): Promise<AnalyseOutcome> {
  const target = publicPage(page.url);
  if (!target) return { ok: false, reason: 'unsupported' };
  const lic = await currentAiLicense();
  if (!lic) return { ok: false, reason: 'not_ai' };
  if (signal?.aborted) return { ok: false, reason: 'cancelled' };

  const ctrl = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => { timedOut = true; ctrl.abort(); }, ANALYSE_TIMEOUT_MS);
  const onAbort = () => ctrl.abort();
  signal?.addEventListener('abort', onAbort);
  let status: number;
  let body: Record<string, unknown> | null;
  try {
    const res = await fetch(`${API_BASE}/api.php?action=analyse`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        key: lic.key,
        url: target.url,
        title: page.title.slice(0, TITLE_MAX),
        description: page.description.slice(0, DESCRIPTION_MAX),
        design: designSummary(tokens),
        lang,
      }),
      signal: ctrl.signal,
    });
    status = res.status;
    body = (await res.json().catch(() => null)) as Record<string, unknown> | null;
  } catch {
    return { ok: false, reason: timedOut ? 'timeout' : signal?.aborted ? 'cancelled' : 'network' };
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', onAbort);
  }

  const error = typeof body?.error === 'string' ? body.error : '';
  if (status === 200 && body?.ok === true) {
    const result = cleanResult(body.result);
    const usage = parseUsage(body.usage);
    if (!result || !usage) return { ok: false, reason: 'invalid' };
    const cached = body.cached === true;
    await storeAiUsage(usage).catch(() => {});
    await keepResult(target.host, { result, usage, analysedAt: new Date().toISOString(), lang, cached }).catch(() => {});
    return { ok: true, result, cached, usage };
  }
  if (status === 403 && error === 'err_ended') {
    return typeof body?.ended_on === 'string' ? { ok: false, reason: 'ended', endedOn: body.ended_on } : { ok: false, reason: 'ended' };
  }
  if (status === 403) return { ok: false, reason: 'not_ai' };
  if (status === 429 && error === 'err_quota') {
    const usage = parseUsage(body?.usage);
    if (usage) await storeAiUsage(usage).catch(() => {});
    const resetsOn = typeof body?.resets_on === 'string' ? body.resets_on : usage?.resetsOn;
    return resetsOn ? { ok: false, reason: 'quota', resetsOn } : { ok: false, reason: 'quota' };
  }
  if (status === 429) return { ok: false, reason: 'rate_limited' };
  if (status === 400 || status === 413) return { ok: false, reason: 'bad_request' };
  return { ok: false, reason: 'unavailable' };
}
