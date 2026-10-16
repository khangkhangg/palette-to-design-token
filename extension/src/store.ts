import type { Tokens } from './model/tokens';

export interface Settings { theme: 'light' | 'dark' | 'system'; lang: string; pro: boolean }

const DEFAULTS: Settings = { theme: 'system', lang: '', pro: false };
const FREE_SCAN_LIMIT = 3;

const merge = (stored: unknown): Settings => ({ ...DEFAULTS, ...((stored as Partial<Settings>) ?? {}) });

export async function getSettings(): Promise<Settings> {
  const { settings } = await chrome.storage.local.get('settings');
  return merge(settings);
}

export async function setSettings(p: Partial<Settings>): Promise<void> {
  await chrome.storage.local.set({ settings: { ...(await getSettings()), ...p } });
}

export async function listScans(): Promise<Tokens[]> {
  const { scans } = await chrome.storage.local.get('scans');
  return (scans as Tokens[] | undefined) ?? [];
}

export async function saveScan(t: Tokens): Promise<void> {
  const [{ pro }, existing] = await Promise.all([getSettings(), listScans()]);
  const next = [t, ...existing.filter((s) => s.site !== t.site)];
  await chrome.storage.local.set({ scans: pro ? next : next.slice(0, FREE_SCAN_LIMIT) });
}

export function onSettingsChange(cb: (s: Settings) => void): () => void {
  const listener = (changes: Record<string, chrome.storage.StorageChange>, area: string) => {
    if (area === 'local' && changes.settings) cb(merge(changes.settings.newValue));
  };
  chrome.storage.onChanged.addListener(listener);
  return () => chrome.storage.onChanged.removeListener(listener);
}
