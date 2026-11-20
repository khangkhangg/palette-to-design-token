/** The side panel's end of the on-page editor: injects edit.js into the active tab and talks to it over a port. */
import { classify, ScanError } from '../scan';
import { PORT_NAME, type Command, type Edits, type EditorState, type PageMessage, type TokenSpec } from './types';

export interface EditorLink {
  send(cmd: Command): void;
  /** Ends the session; the page is put back as it was. */
  close(): void;
}

export interface EditorHandlers {
  onState(state: EditorState): void;
  /** The page went away (navigation, reload, tab closed) without the panel closing the link. */
  onLost(): void;
}

export async function openEditor(
  specs: TokenSpec[],
  replay: Pick<Edits, 'values' | 'added'> | undefined,
  handlers: EditorHandlers,
): Promise<EditorLink> {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || tab.id === undefined) throw new ScanError('no-tab');
  let port: chrome.runtime.Port;
  try {
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['edit.js'] });
    port = chrome.tabs.connect(tab.id, { name: PORT_NAME });
  } catch (e) {
    throw classify(e);
  }
  let open = true;
  port.onMessage.addListener((m: PageMessage) => { if (open && m?.type === 'state') handlers.onState(m.state); });
  port.onDisconnect.addListener(() => {
    void chrome.runtime.lastError; // read so Chrome does not log "Unchecked runtime.lastError"
    if (!open) return;
    open = false;
    handlers.onLost();
  });
  const send = (cmd: Command): void => {
    if (!open) return;
    try { port.postMessage(cmd); } catch { /* disconnected; onDisconnect reports it */ }
  };
  send({ type: 'init', specs, replay });
  return {
    send,
    close() {
      if (!open) return;
      open = false;
      port.disconnect();
    },
  };
}
