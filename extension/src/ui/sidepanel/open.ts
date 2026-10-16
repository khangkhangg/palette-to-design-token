export type PanelView = 'system' | 'export';

/** Opens the side panel on `view` for the active tab. Call straight from a click handler (needs the user gesture). */
export async function openSidePanel(view: PanelView): Promise<void> {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || tab.id === undefined) return;
  await chrome.sidePanel.setOptions({ tabId: tab.id, path: `sidepanel.html?view=${view}`, enabled: true });
  await chrome.sidePanel.open({ tabId: tab.id });
}

export function viewFromSearch(search: string): PanelView {
  return new URLSearchParams(search).get('view') === 'export' ? 'export' : 'system';
}
