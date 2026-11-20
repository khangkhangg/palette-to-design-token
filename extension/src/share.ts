import { API_BASE } from './config';
import { classify } from './scan';

/** Share to directory: talks to `{API_BASE}/api.php` with the extension's own Bearer token, never the site cookie. */
export const connectUrl = (extId: string) => `${API_BASE}/directory/connect?ext=${encodeURIComponent(extId)}`;
const api = (action: string) => `${API_BASE}/api.php?action=${action}`;

export interface Style { slug: string; name: string }
export interface Shot { blob: Blob; width: number; height: number }
export interface Upload { url: string; title: string; style: string; tokens: string; figma?: string; shot: Blob }

export function cleanUrl(url: string): string {
  const u = new URL(url);
  u.search = '';
  u.hash = '';
  return u.href;
}

const BY_STATUS: Record<number, string> = { 401: 'token_revoked', 403: 'blocked', 413: 'too_large', 429: 'rate_limited' };

/** The error code for a failed request. nginx answers 413 with HTML, so the status wins over the body. */
export function errorCode(status: number, body: unknown): string {
  if (status === 0) return 'offline';
  const e = (body as { error?: unknown } | null)?.error;
  return BY_STATUS[status] ?? (typeof e === 'string' ? e : 'failed');
}

export function uploadForm(u: Upload): FormData {
  const f = new FormData();
  f.append('url', cleanUrl(u.url));
  f.append('title', u.title);
  f.append('style', u.style);
  f.append('consent', '1');
  f.append('tokens', new Blob([u.tokens], { type: 'application/json' }), 'tokens.json');
  if (u.figma) f.append('figma', new Blob([u.figma], { type: 'application/json' }), 'figma-variables.json');
  f.append('shot', u.shot, 'screenshot.webp');
  return f;
}

/** The member's status and the style list. Throws an Error whose message is the error code. */
export async function directoryMe(token: string): Promise<{ status: string; styles: Style[] }> {
  let r: Response;
  try {
    r = await fetch(api('dir_me'), { headers: { Authorization: `Bearer ${token}` } });
  } catch {
    throw new Error('offline');
  }
  const b = await r.json().catch(() => null);
  if (!r.ok || !b?.ok) throw new Error(errorCode(r.status, b));
  return b;
}

/** XMLHttpRequest because fetch reports no upload progress. Rejects with the error code, or 'aborted'. */
export function upload(token: string, form: FormData, onProgress: (sent: number, total: number) => void, signal: AbortSignal): Promise<{ slug: string; url: string }> {
  return new Promise((resolve, reject) => {
    const x = new XMLHttpRequest();
    x.open('POST', api('dir_upload'));
    x.setRequestHeader('Authorization', `Bearer ${token}`);
    x.responseType = 'json';
    x.upload.onprogress = (e) => onProgress(e.loaded, e.total);
    x.onload = () => (x.status === 200 && x.response?.ok ? resolve(x.response) : reject(new Error(errorCode(x.status, x.response))));
    x.onerror = () => reject(new Error('offline'));
    x.onabort = () => reject(new Error('aborted'));
    signal.addEventListener('abort', () => x.abort());
    x.send(form);
  });
}

/** Runs in the page: hides the Edit on page overlay (or shows it again) and waits for the next paint. */
const overlayOff = (off: boolean) =>
  new Promise<void>((done) => {
    document.querySelector('sdt-edit-overlay')?.toggleAttribute('data-off', off);
    requestAnimationFrame(() => requestAnimationFrame(() => done()));
  });

/** The analysed tab's visible part as WebP, at most 1,600 px wide. `tabId` is the analysed tab when known. */
export async function capture(site: string, tabId?: number): Promise<Shot> {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || (tabId !== undefined && tab.id !== tabId)) throw new Error('other_tab');
  // No url means the activeTab grant ended, which happens when the tab navigates.
  if (!tab.url || new URL(tab.url).hostname !== site) throw new Error('navigated');
  const target = { tabId: tab.id };
  await chrome.scripting.executeScript({ target, func: overlayOff, args: [true] }).catch(() => {});
  let png: string;
  try {
    png = await chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
  } catch (e) {
    throw new Error(classify(e).reason === 'restricted' ? 'restricted' : 'navigated');
  } finally {
    await chrome.scripting.executeScript({ target, func: overlayOff, args: [false] }).catch(() => {});
  }
  const img = await createImageBitmap(await (await fetch(png)).blob());
  const scale = Math.min(1, 1600 / img.width);
  const c = new OffscreenCanvas(Math.round(img.width * scale), Math.round(img.height * scale));
  c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
  return { blob: await c.convertToBlob({ type: 'image/webp', quality: 0.8 }), width: c.width, height: c.height };
}
