import { activate } from './license';

chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: false }).catch(() => {});
// Off everywhere by default: the popup enables it per tab (openSidePanel), so the panel only
// follows the user to tabs where they opened it rather than auto-scanning every tab they switch to.
chrome.sidePanel.setOptions({ enabled: false }).catch(() => {});

// The checkout page hands the new key over; only our own pages on vietnamcos.com may do so.
const TRUSTED = ['https://vietnamcos.com/palette/', 'https://www.vietnamcos.com/palette/'];

chrome.runtime.onMessageExternal.addListener((message, sender, sendResponse) => {
  const m = message as { type?: unknown; key?: unknown } | null;
  if (!sender.url || !TRUSTED.some((p) => sender.url!.startsWith(p))) return;
  if (!m || m.type !== 'license' || typeof m.key !== 'string') return;
  activate(m.key).then(
    (r) => sendResponse(r.ok ? { ok: true } : { ok: false, reason: r.reason }),
    () => sendResponse({ ok: false, reason: 'network' }),
  );
  return true;
});
export {};
