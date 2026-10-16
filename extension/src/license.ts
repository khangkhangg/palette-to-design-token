import { API_BASE } from './config';
import { getSettings, setSettings } from './store';

export { API_BASE };

export interface License { key: string; verifiedAt: string; emailMasked: string | null }
export type ActivateResult = { ok: true } | { ok: false; reason: 'invalid' | 'revoked' | 'network' | 'rate_limited' };

const LICENSE_KEY = 'license';
const RECHECK_MS = 30 * 24 * 60 * 60 * 1000;
const TIMEOUT_MS = 10_000;
const KEY_SHAPE = /^SDT-[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){2}$/;

/** Trims, uppercases and applies the Crockford look-alike mapping (O to 0, I/L to 1). */
function normalise(raw: string): string {
  return raw.trim().toUpperCase().replace(/O/g, '0').replace(/[IL]/g, '1');
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
    const body = (await res.json()) as { ok?: boolean; valid?: boolean; revoked?: boolean; email_masked?: string | null };
    if (body.revoked) return { kind: 'revoked' };
    if (body.ok && body.valid) return { kind: 'valid', emailMasked: body.email_masked ?? null };
    return { kind: 'invalid' };
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
  if (age <= RECHECK_MS) return;
  const v = await verify(lic.key);
  if (v.kind === 'valid') {
    await chrome.storage.local.set({ [LICENSE_KEY]: { ...lic, verifiedAt: now.toISOString(), emailMasked: v.emailMasked ?? lic.emailMasked } });
    if (!(await getSettings()).pro) await setSettings({ pro: true });
  } else if (v.kind === 'invalid' || v.kind === 'revoked') {
    await chrome.storage.local.remove(LICENSE_KEY);
    await setSettings({ pro: false });
  }
}

export function checkoutUrl(lang: string): string {
  return `${API_BASE}/checkout?lang=${encodeURIComponent(lang)}&ext=${chrome.runtime.id}`;
}

export function lostKeyUrl(lang: string): string {
  return `${API_BASE}/checkout?lang=${encodeURIComponent(lang)}#lost`;
}
