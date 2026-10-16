import { API_BASE } from './config';
import { getSettings, setSettings } from './store';

export { API_BASE };

export interface License { key: string; verifiedAt: string; emailMasked: string | null }
export type ActivateResult = { ok: true } | { ok: false; reason: 'invalid' | 'revoked' | 'network' | 'rate_limited' };

const LICENSE_KEY = 'license';
const RECHECK_MS = 30 * 24 * 60 * 60 * 1000;
const TIMEOUT_MS = 10_000;
const KEY_SHAPE = /^SDT-[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){2}$/;

/**
 * Same rule as the server's palette_normalise_key: keep letters and digits only, uppercase,
 * map Crockford look-alikes (O to 0, I/L to 1) and, when that leaves SDT plus 12 characters,
 * re-insert the dashes — so "SDT ABCD 2345 6789" and "sdtabcd23456789" both work.
 */
function normalise(raw: string): string {
  const s = raw.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (s.length !== 15 || !s.startsWith('SDT')) return s;
  const body = s.slice(3).replace(/O/g, '0').replace(/[IL]/g, '1');
  return `SDT-${body.slice(0, 4)}-${body.slice(4, 8)}-${body.slice(8)}`;
}

type Verdict =
  | { kind: 'valid'; emailMasked: string | null }
  | { kind: 'invalid' | 'revoked' | 'network' | 'rate_limited' };

async function verify(key: string): Promise<Verdict> {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
  try {
    const res = await fetch(`${API_BASE}/api.php?action=verify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key }),
      signal: ctrl.signal,
    });
    if (res.status === 429) return { kind: 'rate_limited' };
    if (res.status !== 200) return { kind: 'network' };
    const body = (await res.json()) as { ok?: unknown; valid?: unknown; revoked?: unknown; email_masked?: unknown } | null;
    // Only an explicit verdict from our API counts. Anything else on a 200 (a proxy's {},
    // {"ok":false}, an HTML page) is a network failure, so a re-check never drops Pro on it.
    if (body?.ok !== true) return { kind: 'network' };
    if (body.revoked === true) return { kind: 'revoked' };
    if (body.valid === true) return { kind: 'valid', emailMasked: typeof body.email_masked === 'string' ? body.email_masked : null };
    if (body.valid === false) return { kind: 'invalid' };
    return { kind: 'network' };
  } catch {
    return { kind: 'network' };
  } finally {
    clearTimeout(timer);
  }
}

async function readLicense(): Promise<License | null> {
  const { [LICENSE_KEY]: lic } = await chrome.storage.local.get(LICENSE_KEY);
  const l = lic as Partial<License> | undefined;
  return l && typeof l.key === 'string' && typeof l.verifiedAt === 'string'
    ? { key: l.key, verifiedAt: l.verifiedAt, emailMasked: l.emailMasked ?? null }
    : null;
}

export async function activate(rawKey: string): Promise<ActivateResult> {
  const key = normalise(rawKey);
  if (!KEY_SHAPE.test(key)) return { ok: false, reason: 'invalid' };
  const v = await verify(key);
  if (v.kind !== 'valid') return { ok: false, reason: v.kind };
  const lic: License = { key, verifiedAt: new Date().toISOString(), emailMasked: v.emailMasked };
  await chrome.storage.local.set({ [LICENSE_KEY]: lic });
  await setSettings({ pro: true });
  return { ok: true };
}

export async function recheckIfDue(now: Date = new Date()): Promise<void> {
  const lic = await readLicense();
  if (!lic) return;
  const age = now.getTime() - new Date(lic.verifiedAt).getTime();
  const unparseable = Number.isNaN(age);
  if (!unparseable && age <= RECHECK_MS) return;
  const v = await verify(lic.key);
  if (v.kind === 'valid') {
    await chrome.storage.local.set({ [LICENSE_KEY]: { ...lic, verifiedAt: now.toISOString(), emailMasked: v.emailMasked ?? lic.emailMasked } });
    if (!(await getSettings()).pro) await setSettings({ pro: true });
  } else if (v.kind === 'invalid' || v.kind === 'revoked') {
    await chrome.storage.local.remove(LICENSE_KEY);
    await setSettings({ pro: false });
  } else if (unparseable) {
    // Due once; rewrite the date so a failed check doesn't repeat on every open.
    await chrome.storage.local.set({ [LICENSE_KEY]: { ...lic, verifiedAt: now.toISOString() } });
  }
}

export function checkoutUrl(lang: string): string {
  return `${API_BASE}/checkout?lang=${encodeURIComponent(lang)}&ext=${chrome.runtime.id}`;
}

export function lostKeyUrl(lang: string): string {
  return `${API_BASE}/checkout?lang=${encodeURIComponent(lang)}#resend`;
}

/** The licence stored in this browser, if any. */
export function currentLicense(): Promise<License | null> {
  return readLicense();
}

/** "Remove key from this browser": forget the licence and turn Pro off. The key itself stays valid. */
export async function removeLicense(): Promise<void> {
  await chrome.storage.local.remove(LICENSE_KEY);
  await setSettings({ pro: false });
}
