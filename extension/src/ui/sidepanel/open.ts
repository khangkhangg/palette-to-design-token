/** `unlock` is the plan chooser; `key` opens straight on the license key form. */
export type PanelView = 'system' | 'export' | 'unlock' | 'key';

export async function activeTabId(): Promise<number | null> {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    return tab?.id ?? null;
  } catch {
    return null;
  }
}

/**
 * Opens the side panel on `view`. Call straight from a click handler with a tabId resolved earlier:
 * setOptions is not awaited and open is the first awaited call, so the user gesture is still live.
 */
export async function openSidePanel(tabId: number, view: PanelView): Promise<void> {
  const options = chrome.sidePanel.setOptions({ tabId, path: `sidepanel.html?view=${view}`, enabled: true });
  options.catch(() => {});
  await chrome.sidePanel.open({ tabId });
  await options;
}

export function viewFromSearch(search: string): PanelView {
  const view = new URLSearchParams(search).get('view');
  return view === 'export' || view === 'unlock' || view === 'key' ? view : 'system';
}
