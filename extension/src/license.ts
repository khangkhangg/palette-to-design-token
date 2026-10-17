import { API_BASE } from './config';
import { getSettings, setSettings } from './store';

export { API_BASE };

export interface AiUsage { used: number; limit: number; resetsOn: string }
export interface License {
  key: string;
  verifiedAt: string;
  emailMasked: string | null;
  plan: 'pro' | 'ai';
  paidThrough: string | null;
}
/** The Pro + AI licence as stored: what the last check said, so the state can be worked out offline. */
export interface AiLicense extends License {
  plan: 'ai';
  usage: AiUsage | null;
  /** Cancelled subscriptions keep access until paidThrough; the server may say so. */
  subStatus: string | null;
  /** 'ended' = the server said the plan lapsed; 'network' = the last check could not reach it. */
  lastCheck: 'ok' | 'network' | 'ended';
  /** When the last check was tried (any outcome); throttles the checks that are not on the daily schedule. */
  lastAttemptAt: string | null;
}
export type ActivateResult =
  | { ok: true }
  | { ok: false; reason: 'invalid' | 'revoked' | 'ended' | 'network' | 'rate_limited' };

const LICENSE_KEY = 'license';
const AI_KEY = 'licenseAi';
const RECHECK_MS = 30 * 24 * 60 * 60 * 1000;
const AI_RECHECK_MS = 24 * 60 * 60 * 1000;
/** After paidThrough, an AI plan whose renewal check cannot reach the server stays on this long. */
const AI_GRACE_MS = 3 * 24 * 60 * 60 * 1000;
const AI_SKEW_MS = 60 * 60 * 1000;
const AI_RETRY_MS = 30 * 60 * 1000;
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

interface AiInfo { paidThrough: string | null; usage: AiUsage | null; subStatus: string | null }
type Verdict =
  | { kind: 'valid'; emailMasked: string | null; ai: AiInfo | null }
  | { kind: 'ended'; ai: AiInfo }
  | { kind: 'invalid' | 'revoked' | 'network' | 'rate_limited' };

const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);

function parseUsage(raw: unknown): AiUsage | null {
  const u = raw as { used?: unknown; limit?: unknown; resets_on?: unknown } | null;
  return u && typeof u.used === 'number' && typeof u.limit === 'number' && typeof u.resets_on === 'string'
    ? { used: u.used, limit: u.limit, resetsOn: u.resets_on }
    : null;
}

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
    const body = (await res.json()) as Record<string, unknown> | null;
    // Only an explicit verdict from our API counts. Anything else on a 200 (a proxy's {},
    // {"ok":false}, an HTML page) is a network failure, so a re-check never drops Pro on it.
    if (body?.ok !== true) return { kind: 'network' };
    if (body.revoked === true) return { kind: 'revoked' };
    const ai: AiInfo | null = body.plan === 'ai'
      ? { paidThrough: str(body.paid_through), usage: parseUsage(body.usage), subStatus: str(body.sub_status) }
      : null;
    // A lapsed AI plan answers valid:false, revoked:false, plan:'ai': ended, not an invalid key.
    if (ai && (body.valid === false || (body.valid === true && body.ai_active === false))) return { kind: 'ended', ai };
    if (body.valid === true) return { kind: 'valid', emailMasked: str(body.email_masked), ai };
    if (body.valid === false) return { kind: 'invalid' };
    return { kind: 'network' };
  } catch {
    return { kind: 'network' };
  } finally {
    clearTimeout(timer);
  }
}

async function readProLicense(): Promise<License | null> {
  const { [LICENSE_KEY]: lic } = await chrome.storage.local.get(LICENSE_KEY);
  const l = lic as Partial<License> | undefined;
  return l && typeof l.key === 'string' && typeof l.verifiedAt === 'string'
    ? { key: l.key, verifiedAt: l.verifiedAt, emailMasked: l.emailMasked ?? null, plan: 'pro', paidThrough: null }
    : null;
}

async function readAiLicense(): Promise<AiLicense | null> {
  const { [AI_KEY]: lic } = await chrome.storage.local.get(AI_KEY);
  const l = lic as Partial<AiLicense> | undefined;
  return l && typeof l.key === 'string' && typeof l.verifiedAt === 'string'
    ? {
      key: l.key, verifiedAt: l.verifiedAt, emailMasked: l.emailMasked ?? null, plan: 'ai',
      paidThrough: l.paidThrough ?? null, usage: l.usage ?? null, subStatus: l.subStatus ?? null,
      lastCheck: l.lastCheck ?? 'ok', lastAttemptAt: l.lastAttemptAt ?? null,
    }
    : null;
}

const time = (iso: string | null): number => (iso ? new Date(iso).getTime() : NaN);

function aiActive(l: AiLicense | null, now: number): boolean {
  if (!l || l.lastCheck === 'ended') return false;
  const through = time(l.paidThrough);
  if (Number.isNaN(through)) return false;
  // 'ok' gets a short grace so clock skew or a renewal that is a few minutes behind does not read as ended;
  // a check that could not reach the server gets the full 3 days.
  return now < through + (l.lastCheck === 'network' ? AI_GRACE_MS : AI_SKEW_MS);
}

/** Whether the Pro + AI plan is currently active, worked out from what the last check stored. */
export async function aiState(now: Date = new Date()): Promise<{ active: boolean; paidThrough: string | null; usage: AiUsage | null }> {
  const l = await readAiLicense();
  return { active: aiActive(l, now.getTime()), paidThrough: l?.paidThrough ?? null, usage: l?.usage ?? null };
}

/**
 * Settings.pro stays the single Pro flag: a stored Pro licence, or an active Pro + AI plan. It is only
 * touched when a licence is stored or one changed in this call, so the dev toggle and seeded state survive.
 */
async function syncPro(changed: boolean, now: Date = new Date()): Promise<void> {
  const [proLic, aiLic] = await Promise.all([readProLicense(), readAiLicense()]);
  if (!changed && !proLic && !aiLic) return;
  const pro = proLic !== null || aiActive(aiLic, now.getTime());
  if ((await getSettings()).pro !== pro) await setSettings({ pro });
}

export async function activate(rawKey: string): Promise<ActivateResult> {
  const key = normalise(rawKey);
  if (!KEY_SHAPE.test(key)) return { ok: false, reason: 'invalid' };
  const v = await verify(key);
  if (v.kind === 'ended') return { ok: false, reason: 'ended' };
  if (v.kind !== 'valid') return { ok: false, reason: v.kind };
  const verifiedAt = new Date().toISOString();
  if (v.ai) {
    const lic: AiLicense = {
      key, verifiedAt, emailMasked: v.emailMasked, plan: 'ai', paidThrough: v.ai.paidThrough,
      usage: v.ai.usage, subStatus: v.ai.subStatus, lastCheck: 'ok', lastAttemptAt: verifiedAt,
    };
    await chrome.storage.local.set({ [AI_KEY]: lic });
  } else {
    const lic: License = { key, verifiedAt, emailMasked: v.emailMasked, plan: 'pro', paidThrough: null };
    await chrome.storage.local.set({ [LICENSE_KEY]: lic });
  }
  await syncPro(true);
  return { ok: true };
}

/** Returns true when it removed the licence. */
async function recheckPro(now: Date): Promise<boolean> {
  const lic = await readProLicense();
  if (!lic) return false;
  const age = now.getTime() - new Date(lic.verifiedAt).getTime();
  const unparseable = Number.isNaN(age);
  if (!unparseable && age <= RECHECK_MS) return false;
  const v = await verify(lic.key);
  if (v.kind === 'valid') {
    await chrome.storage.local.set({ [LICENSE_KEY]: { ...lic, verifiedAt: now.toISOString(), emailMasked: v.emailMasked ?? lic.emailMasked } });
  } else if (v.kind === 'invalid' || v.kind === 'revoked') {
    await chrome.storage.local.remove(LICENSE_KEY);
    return true;
  } else if (unparseable) {
    // Due once; rewrite the date so a failed check doesn't repeat on every open.
    await chrome.storage.local.set({ [LICENSE_KEY]: { ...lic, verifiedAt: now.toISOString() } });
  }
  return false;
}

/** Due daily; also once the paid period has run out and for a recently ended plan (a renewal can lag), at most every 30 minutes. */
function aiDue(lic: AiLicense, now: number): boolean {
  const verified = time(lic.verifiedAt);
  if (Number.isNaN(verified) || now - verified > AI_RECHECK_MS) return true;
  const through = time(lic.paidThrough);
  const pastEnd = lic.lastCheck !== 'ended' && !Number.isNaN(through) && now >= through;
  const recentlyEnded = lic.lastCheck === 'ended' && (Number.isNaN(through) || now < through + AI_GRACE_MS);
  if (!pastEnd && !recentlyEnded) return false;
  const attempt = time(lic.lastAttemptAt);
  return Number.isNaN(attempt) || now - attempt >= AI_RETRY_MS;
}

/** Returns true when it removed the licence. */
async function recheckAi(now: Date): Promise<boolean> {
  const lic = await readAiLicense();
  if (!lic || !aiDue(lic, now.getTime())) return false;
  const unparseable = Number.isNaN(time(lic.verifiedAt));
  const v = await verify(lic.key);
  const stamp = now.toISOString();
  if (v.kind === 'valid' && v.ai) {
    await chrome.storage.local.set({
      [AI_KEY]: {
        ...lic, verifiedAt: stamp, lastAttemptAt: stamp, emailMasked: v.emailMasked ?? lic.emailMasked,
        paidThrough: v.ai.paidThrough ?? lic.paidThrough, usage: v.ai.usage ?? lic.usage,
        subStatus: v.ai.subStatus ?? lic.subStatus, lastCheck: 'ok',
      } satisfies AiLicense,
    });
  } else if (v.kind === 'ended') {
    // Lapsed: keep the key so the menu can offer Renew, but the plan is over.
    await chrome.storage.local.set({
      [AI_KEY]: {
        ...lic, verifiedAt: stamp, lastAttemptAt: stamp, paidThrough: v.ai.paidThrough ?? lic.paidThrough,
        usage: v.ai.usage ?? lic.usage, subStatus: v.ai.subStatus ?? lic.subStatus, lastCheck: 'ended',
      } satisfies AiLicense,
    });
  } else if (v.kind === 'revoked' || v.kind === 'invalid') {
    await chrome.storage.local.remove(AI_KEY);
    return true;
  } else {
    // Network failure, rate limit, or an answer that was not an AI verdict: keep what we had and
    // remember the check failed, so the 3-day grace after paidThrough applies. verifiedAt stays old
    // (the next open tries again) unless it was unreadable.
    await chrome.storage.local.set({
      [AI_KEY]: {
        ...lic, verifiedAt: unparseable ? stamp : lic.verifiedAt, lastAttemptAt: stamp,
        lastCheck: lic.lastCheck === 'ended' ? 'ended' : 'network',
      } satisfies AiLicense,
    });
  }
  return false;
}

export async function recheckIfDue(now: Date = new Date()): Promise<void> {
  const removedPro = await recheckPro(now);
  const removedAi = await recheckAi(now);
  await syncPro(removedPro || removedAi, now);
}

export function checkoutUrl(lang: string, plan?: 'pro' | 'ai'): string {
  return `${API_BASE}/checkout?lang=${encodeURIComponent(lang)}&ext=${chrome.runtime.id}${plan ? `&plan=${plan}` : ''}`;
}

export function lostKeyUrl(lang: string): string {
  return `${API_BASE}/checkout?lang=${encodeURIComponent(lang)}#resend`;
}

export function accountUrl(): string {
  return `${API_BASE}/account`;
}

/** The Pro licence stored in this browser, if any. */
export function currentLicense(): Promise<License | null> {
  return readProLicense();
}

/** The Pro + AI licence stored in this browser, if any (active or ended). */
export function currentAiLicense(): Promise<AiLicense | null> {
  return readAiLicense();
}

/** Keeps the usage an analyse answer reported on the stored Pro + AI licence, so aiState() shows it. */
export async function storeAiUsage(usage: AiUsage): Promise<void> {
  const lic = await readAiLicense();
  if (lic) await chrome.storage.local.set({ [AI_KEY]: { ...lic, usage } satisfies AiLicense });
}

/** "Remove key from this browser": forget one licence and recompute Pro. The key itself stays valid. */
export async function removeLicense(plan: 'pro' | 'ai' = 'pro'): Promise<void> {
  await chrome.storage.local.remove(plan === 'ai' ? AI_KEY : LICENSE_KEY);
  await syncPro(true);
}
