/** The editor's own marks on the page: hover and selection outlines, a drop line, and "used here" boxes. Injected, so no imports from the page. */

export interface Box { left: number; top: number; width: number; height: number }

export interface Overlay {
  host: Element;
  hover(box: Box | null, label?: string): void;
  select(box: Box | null, label?: string): void;
  /** The line showing where a dragged element will land; `note` follows the pointer. */
  drop(line: Box | null, note?: { x: number; y: number; text: string }): void;
  /** Dashed boxes around the elements that use the token the panel is pointing at. */
  marks(boxes: Box[]): void;
  remove(): void;
}

const CSS = `
  :host { all: initial !important; position: fixed !important; inset: 0 !important; pointer-events: none !important; z-index: 2147483647 !important; display: block !important; }
  /* Share to directory hides the overlay while it takes the screenshot. */
  :host([data-off]) { display: none !important; }
  .box, .line, .tag, .mark { position: fixed; box-sizing: border-box; pointer-events: none; }
  .box { border: 2px solid #D6249F; border-radius: 2px; }
  .box.sel { border-color: #3B4BD8; box-shadow: 0 0 0 4px rgba(59, 75, 216, .18); }
  .mark { border: 2px dashed #D6249F; border-radius: 2px; }
  .line { background: #D6249F; border-radius: 2px; box-shadow: 0 0 0 2px rgba(214, 36, 159, .25); }
  .tag {
    font: 500 11px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #fff; background: #D6249F;
    padding: 4px 6px; border-radius: 4px; white-space: nowrap; max-width: 60vw; overflow: hidden; text-overflow: ellipsis;
  }
  .tag.sel { background: #3B4BD8; }
  [hidden] { display: none; }
`;

const TAG_HEIGHT = 19;

function place(el: HTMLElement, b: Box): void {
  el.hidden = false;
  el.style.left = `${b.left}px`;
  el.style.top = `${b.top}px`;
  el.style.width = `${b.width}px`;
  el.style.height = `${b.height}px`;
}

export function createOverlay(doc: Document): Overlay {
  const host = doc.createElement('sdt-edit-overlay');
  host.setAttribute('aria-hidden', 'true');
  const root = host.attachShadow({ mode: 'closed' });
  const style = doc.createElement('style');
  style.textContent = CSS;
  root.append(style);

  const make = (cls: string): HTMLElement => {
    const el = doc.createElement('div');
    el.className = cls;
    el.hidden = true;
    root.append(el);
    return el;
  };
  const marksWrap = make('marks');
  marksWrap.hidden = false;
  const hoverBox = make('box');
  const hoverTag = make('tag');
  const selBox = make('box sel');
  const selTag = make('tag sel');
  const line = make('line');
  const note = make('tag');

  const outline = (box: HTMLElement, tag: HTMLElement, b: Box | null, label?: string): void => {
    if (!b) { box.hidden = true; tag.hidden = true; return; }
    place(box, b);
    tag.hidden = !label;
    if (!label) return;
    tag.textContent = label;
    // Above the element, or just inside it when there is no room above.
    tag.style.left = `${Math.max(0, b.left)}px`;
    tag.style.top = `${b.top >= TAG_HEIGHT ? b.top - TAG_HEIGHT : Math.max(0, b.top) + 2}px`;
  };

  (doc.documentElement ?? doc.body).append(host);

  return {
    host,
    hover: (b, label) => outline(hoverBox, hoverTag, b, label),
    select: (b, label) => outline(selBox, selTag, b, label),
    drop(l, n) {
      if (l) place(line, l); else line.hidden = true;
      note.hidden = !n;
      if (n) {
        note.textContent = n.text;
        note.style.left = `${n.x + 12}px`;
        note.style.top = `${n.y + 14}px`;
      }
    },
    marks(boxes) {
      marksWrap.textContent = '';
      for (const b of boxes) {
        const m = doc.createElement('div');
        m.className = 'mark';
        place(m, b);
        marksWrap.append(m);
      }
    },
    remove: () => host.remove(),
  };
}
