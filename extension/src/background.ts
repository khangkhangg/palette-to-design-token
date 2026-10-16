chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: false }).catch(() => {});
// Off everywhere by default: the popup enables it per tab (openSidePanel), so the panel only
// follows the user to tabs where they opened it rather than auto-scanning every tab they switch to.
chrome.sidePanel.setOptions({ enabled: false }).catch(() => {});
export {};
