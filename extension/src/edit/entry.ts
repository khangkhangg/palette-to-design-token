/** edit.js: injected on demand. The side panel connects a port; the editor lives until that port closes, then puts the page back. */
import { createEditor, type Editor } from './editor';
import { PORT_NAME, type Command, type PageMessage } from './types';

const g = globalThis as unknown as { __sdtEdit?: boolean };

if (!g.__sdtEdit) {
  g.__sdtEdit = true;
  let current: { port: chrome.runtime.Port; editor: Editor } | null = null;

  chrome.runtime.onConnect.addListener((port) => {
    if (port.name !== PORT_NAME) return;
    // One panel edits a page at a time: a new connection replaces the old one.
    if (current) { current.editor.destroy(); current.port.disconnect(); }
    const editor = createEditor(document, (state) => {
      try { port.postMessage({ type: 'state', state } satisfies PageMessage); } catch { /* the panel is gone */ }
    });
    const mine = { port, editor };
    current = mine;
    port.onMessage.addListener((cmd: Command) => editor.handle(cmd));
    port.onDisconnect.addListener(() => {
      editor.destroy();
      if (current === mine) current = null;
    });
  });
}
