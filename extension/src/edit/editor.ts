/**
 * The on-page editor. It works out which scanned token each element uses, repaints the page when a token changes,
 * and lets the user restyle one element, rewrite text, align and move elements. Every change is undoable and the
 * page is put back when the editor is destroyed. Injected into the page as an IIFE, so no runtime imports from the page.
 */
import { composite, deltaE, parseColour, toHex, type RGBA } from '../analyse/colour-math';
import { createOverlay, type Box, type Overlay } from './overlay';
import {
  newTokenName, sameValue,
  type AddedToken, type Align, type Command, type Edits, type EditorState, type ElDesc, type OneOff, type PageEdit,
  type Row, type RowLabel, type Selected, type TokenKind, type TokenSpec, type TokenValue, type VAlign,
} from './types';

export interface Measure {
  rect(el: Element): Box;
  style(el: Element): CSSStyleDeclaration;
}

const defaultMeasure: Measure = {
  rect: (el) => el.getBoundingClientRect(),
  style: (el) => getComputedStyle(el),
};

const LIMIT = 4000;
const SKIP = new Set(['script', 'style', 'noscript', 'template', 'head', 'meta', 'link', 'sdt-edit-overlay']);
const SVG_NS = 'http://www.w3.org/2000/svg';
const SVG_SHAPES = new Set(['path', 'rect', 'circle', 'ellipse', 'polygon', 'polyline', 'text']);
const NO_TEXT_EDIT = new Set(['input', 'textarea', 'select', 'option', 'img', 'svg', 'video', 'canvas', 'iframe', 'html', 'body']);
const WHITE: RGBA = { r: 255, g: 255, b: 255, a: 1 };
const SIDES = ['top', 'right', 'bottom', 'left'] as const;
const MERGE_DELTA_E = 2.5;
const DRAG_START = 6;
const EDGE = 8;
const EDGE_MAX = 24;
const MAX_MARKS = 80;
const TEXT_MAX = 2000;
const NEW_NAME: Record<TokenKind, string> = { colour: 'custom', type: 'text-custom', space: 'space-new', radius: 'radius-new' };

interface Spec {
  id: string; kind: TokenKind; name: string; varName: string;
  /** The scanned value; null for a token the user added. */
  orig: TokenValue | null;
  value: TokenValue;
  from: string | null;
  rgb: RGBA | null;
  users: Map<Element, Set<string>>;
  /** Whether the users' inline styles currently point at the CSS variable. */
  bound: boolean;
}

interface FullRow extends Row { props: string[] }
interface OneOffRec { props: string[]; label: RowLabel; kind: TokenKind; value: TokenValue; token: string | null }
interface PageRec { el: Element; edit: PageEdit; undo(): void }
interface Drag { el: Element; x: number; y: number; active: boolean; saved: [string, string][] }
interface Drop { parent: Element; before: Element | null; line: Box }

type Styled = Element & { style?: CSSStyleDeclaration };

const px = (v: string | undefined): number => parseFloat(v ?? '');
const round = (n: number): number => Math.round(n * 100) / 100;
const cssValue = (kind: TokenKind, v: TokenValue): string => (kind === 'colour' ? String(v) : `${v}px`);
const flat = (s: string | null | undefined): string => (s ?? '').replace(/\s+/g, ' ').trim();

function normalise(kind: TokenKind, v: TokenValue): TokenValue | null {
  if (kind === 'colour') {
    const c = parseColour(String(v));
    return c ? toHex({ ...c, a: 1 }).toUpperCase() : null;
  }
  const n = Number(v);
  return Number.isFinite(n) && n >= 0 && n <= 999 ? round(n) : null;
}

export interface Editor { handle(cmd: Command): void; destroy(): void }

export function createEditor(doc: Document, send: (s: EditorState) => void, measure: Measure = defaultMeasure): Editor {
  const win = doc.defaultView;
  const root = doc.documentElement as Styled;

  const specs = new Map<string, Spec>();
  const binds = new Map<Element, Map<string, string>>();
  const saved = new Map<Element, Map<string, [string, string]>>();
  /** The style attribute each touched element had before the editor wrote to it (null = none), to put back exactly. */
  const attrs = new Map<Element, string | null>();
  const forced = new Map<Element, Map<string, string>>();
  const forcedAxis = new Map<Element, { h?: string[]; v?: string[] }>();
  const oneOffs = new Map<Element, Map<string, OneOffRec>>();
  let page: PageRec[] = [];
  const ids = new WeakMap<Element, number>();
  const byId = new Map<number, Element>();
  let nextId = 1;
  let seq = 0;

  let overlay: Overlay | null = null;
  let picking = false;
  let selected: Element | null = null;
  let hover: Element | null = null;
  let marked: Element[] = [];
  let drag: Drag | null = null;
  let drop: Drop | null = null;
  let justDragged = false;
  let editing: { el: Element; attr: string | null; html: string; from: string } | null = null;

  const idOf = (el: Element): number => {
    let id = ids.get(el);
    if (!id) { id = nextId++; ids.set(el, id); byId.set(id, el); }
    return id;
  };

  // ---- inline styles: every write remembers what was there, so everything can be put back ----

  function write(el: Styled, prop: string, value: string): void {
    if (!el.style) return;
    let m = saved.get(el);
    if (!m) {
      m = new Map();
      saved.set(el, m);
      attrs.set(el, el.getAttribute('style'));
    }
    if (!m.has(prop)) m.set(prop, [el.style.getPropertyValue(prop), el.style.getPropertyPriority(prop)]);
    el.style.setProperty(prop, value, 'important');
  }

  /**
   * Once nothing of ours is left on an element, its style attribute goes back to the text it had: gone if there was
   * none, and the page's own spelling (Chrome re-serialises `margin-top:1rem` as `margin-top: 1rem;`) if there was one,
   * unless the page changed its inline style in the meantime.
   */
  function settle(el: Styled): void {
    const was = attrs.get(el);
    attrs.delete(el);
    if (was === undefined || !el.style) return;
    // Chrome writes the attribute lazily after a CSSOM change; reading it first makes the removal or rewrite stick.
    el.getAttribute('style');
    if (was === null) {
      if (!el.style.length) el.removeAttribute('style');
      return;
    }
    const probe = doc.createElement('span');
    probe.setAttribute('style', was);
    if (probe.style.cssText === el.style.cssText) el.setAttribute('style', was);
  }

  function unwrite(el: Styled, prop: string): void {
    const m = saved.get(el);
    const was = m?.get(prop);
    if (!m || !was || !el.style) return;
    if (was[0]) el.style.setProperty(prop, was[0], was[1]); else el.style.removeProperty(prop);
    m.delete(prop);
    if (m.size) return;
    saved.delete(el);
    settle(el);
  }

  const isActive = (s: Spec): boolean => s.orig === null || !sameValue(s.value, s.orig);

  function oneOffFor(el: Element, prop: string): OneOffRec | undefined {
    const m = oneOffs.get(el);
    if (m) for (const o of m.values()) if (o.props.includes(prop)) return o;
    return undefined;
  }

  /** What an element's property should be right now: an alignment, else a one-off, else its token's variable, else the page's own. */
  function refresh(el: Element, prop: string): void {
    const f = forced.get(el)?.get(prop);
    if (f !== undefined) return write(el, prop, f);
    const o = oneOffFor(el, prop);
    if (o) return write(el, prop, cssValue(o.kind, o.value));
    const id = binds.get(el)?.get(prop);
    const spec = id ? specs.get(id) : undefined;
    if (spec && isActive(spec)) return write(el, prop, `var(${spec.varName})`);
    unwrite(el, prop);
  }

  // ---- tokens ----

  function addSpec(s: TokenSpec, orig: TokenValue | null, from: string | null): Spec {
    const rgb = s.kind === 'colour' ? parseColour(String(orig ?? s.value)) : null;
    const spec: Spec = {
      id: s.id, kind: s.kind, name: s.name, varName: `--sdt-edit-${specs.size + 1}-${++seq}`,
      orig, value: s.value, from, rgb, users: new Map(), bound: false,
    };
    specs.set(s.id, spec);
    return spec;
  }

  function rebind(el: Element, prop: string, id: string | null): void {
    let m = binds.get(el);
    const old = m?.get(prop);
    if (old) {
      const users = specs.get(old)?.users;
      const props = users?.get(el);
      props?.delete(prop);
      if (props && !props.size) users!.delete(el);
    }
    if (!id) { m?.delete(prop); return; }
    if (!m) { m = new Map(); binds.set(el, m); }
    m.set(prop, id);
    const users = specs.get(id)!.users;
    let props = users.get(el);
    if (!props) { props = new Set(); users.set(el, props); }
    props.add(prop);
  }

  function applyToken(spec: Spec): void {
    const active = isActive(spec);
    if (active && !attrs.has(root)) attrs.set(root, root.getAttribute('style'));
    if (active) root.style?.setProperty(spec.varName, cssValue(spec.kind, spec.value));
    else root.style?.removeProperty(spec.varName);
    if (spec.bound === active) return;
    spec.bound = active;
    for (const [el, props] of spec.users) for (const prop of props) refresh(el, prop);
  }

  /** The token a value belongs to, by scanned value (indexing the untouched page) or by current value (an element met later). */
  function match(kind: TokenKind, value: TokenValue, current: boolean): string | null {
    let best: string | null = null;
    let bestD = Infinity;
    const rgb = kind === 'colour' ? parseColour(String(value)) : null;
    for (const s of specs.values()) {
      if (s.kind !== kind) continue;
      const ref = current ? s.value : s.orig;
      if (ref === null) continue;
      let d: number;
      if (kind === 'colour') {
        const other = current ? parseColour(String(ref)) : s.rgb;
        if (!rgb || !other) continue;
        d = deltaE(rgb, other);
        if (d >= MERGE_DELTA_E) continue;
      } else {
        d = Math.abs(Number(value) - Number(ref));
        if (d > (kind === 'type' ? 0.5 : 0.01)) continue;
      }
      if (d < bestD) { bestD = d; best = s.id; }
    }
    return best;
  }

  const hasText = (el: Element): boolean => {
    for (const n of Array.from(el.childNodes)) if (n.nodeType === 3 && (n.textContent ?? '').trim()) return true;
    return false;
  };

  const opaqueHex = (css: string): string | null => {
    const c = parseColour(css);
    return c && c.a > 0 ? toHex(composite(c, WHITE)).toUpperCase() : null;
  };

  const drawnSides = (st: CSSStyleDeclaration): string[] => SIDES.filter((sd) => {
    const style = st.getPropertyValue(`border-${sd}-style`);
    return px(st.getPropertyValue(`border-${sd}-width`)) > 0 && !!style && style !== 'none' && style !== 'hidden';
  });

  const isShape = (el: Element): boolean => el.namespaceURI === SVG_NS && SVG_SHAPES.has(el.localName);

  /** The token-bearing styles of one element: [css property, kind, value]. Mirrors what the scan samples. */
  function stylesOf(el: Element, st: CSSStyleDeclaration): [string, TokenKind, TokenValue][] {
    const out: [string, TokenKind, TokenValue][] = [];
    const colour = (prop: string, css: string): void => {
      const hex = opaqueHex(css);
      if (hex) out.push([prop, 'colour', hex]);
    };
    if (hasText(el)) {
      colour('color', st.color);
      const size = px(st.fontSize);
      if (size > 0) out.push(['font-size', 'type', round(size)]);
    }
    colour('background-color', st.backgroundColor);
    for (const sd of drawnSides(st)) colour(`border-${sd}-color`, st.getPropertyValue(`border-${sd}-color`));
    if (isShape(el) && st.fill && st.fill !== 'none') colour('fill', st.fill);
    for (const sd of SIDES) {
      const p = px(st.getPropertyValue(`padding-${sd}`));
      if (p > 0) out.push([`padding-${sd}`, 'space', round(p)]);
      const m = px(st.getPropertyValue(`margin-${sd}`));
      if (m > 0) out.push([`margin-${sd}`, 'space', round(m)]);
    }
    for (const g of ['row-gap', 'column-gap']) {
      const v = st.getPropertyValue(g);
      if (v && v !== 'normal' && !v.includes('%') && px(v) > 0) out.push([g, 'space', round(px(v))]);
    }
    const r = st.borderTopLeftRadius;
    if (r && !r.includes('%') && px(r) > 0) out.push(['border-radius', 'radius', round(px(r))]);
    return out;
  }

  /** Walks the visible page (as the scan does) and records which token each style uses. */
  function index(): void {
    const start = doc.body ?? doc.documentElement;
    if (!start) return;
    const stack: Element[] = [start];
    let visited = 0;
    while (stack.length && visited < LIMIT) {
      const el = stack.pop()!;
      visited++;
      if (SKIP.has(el.localName)) continue;
      const st = measure.style(el);
      if (st.display === 'none') continue;
      const kids: Element[] = [];
      if (el.shadowRoot) kids.push(...Array.from(el.shadowRoot.children));
      kids.push(...Array.from(el.children));
      for (let i = kids.length - 1; i >= 0; i--) stack.push(kids[i]);
      for (const [prop, kind, value] of stylesOf(el, st)) {
        const id = match(kind, value, false);
        if (id) rebind(el, prop, id);
      }
    }
  }

  // ---- the selected element ----

  const snippet = (text: string): string => (text.length > 40 ? `${text.slice(0, 39)}\u2026` : text);

  /** The first text inside an element that has none of its own (a card's heading), to tell it from its siblings. */
  function firstTextInside(el: Element): string {
    const walker = doc.createTreeWalker(el, 4 /* NodeFilter.SHOW_TEXT */);
    let seen = 0;
    for (let n = walker.nextNode(); n && seen < 200; n = walker.nextNode(), seen++) {
      const text = flat(n.textContent);
      if (text && n.parentElement && !SKIP.has(n.parentElement.localName)) return text;
    }
    return '';
  }

  function describe(el: Element): ElDesc {
    const cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/)[0] : '';
    const own = hasText(el);
    return {
      tag: el.localName,
      text: own ? snippet(flat(el.textContent)) : '',
      hook: el.id ? `#${el.id}` : cls ? `.${cls}` : '',
      inside: own ? '' : snippet(firstTextInside(el)),
    };
  }

  function rowsFor(el: Element): FullRow[] {
    const st = measure.style(el);
    const mine = oneOffs.get(el);
    const rows: FullRow[] = [];
    const add = (key: string, label: RowLabel, kind: TokenKind, props: string[], value: TokenValue | null): void => {
      const o = mine?.get(key);
      if (value === null && !o) return;
      const token = o ? o.token : binds.get(el)?.get(props[0]) ?? (value === null ? null : match(kind, value, true));
      rows.push({ key, label, kind, props: o ? o.props : props, value: o ? o.value : value!, token, overridden: !!o });
    };
    const text = hasText(el);
    if (text) add('color', 'color', 'colour', ['color'], opaqueHex(st.color));
    add('background-color', 'background', 'colour', ['background-color'], opaqueHex(st.backgroundColor));
    const sides = drawnSides(st);
    if (sides.length) {
      add('border-color', 'border', 'colour', sides.map((sd) => `border-${sd}-color`), opaqueHex(st.getPropertyValue(`border-${sides[0]}-color`)));
    }
    if (isShape(el) && st.fill && st.fill !== 'none') add('fill', 'fill', 'colour', ['fill'], opaqueHex(st.fill));
    if (text && px(st.fontSize) > 0) add('font-size', 'size', 'type', ['font-size'], round(px(st.fontSize)));
    const r = st.borderTopLeftRadius;
    if (r && !r.includes('%') && px(r) > 0) add('border-radius', 'radius', 'radius', ['border-radius'], round(px(r)));

    const [pt, pr, pb, pl] = SIDES.map((sd) => round(px(st.getPropertyValue(`padding-${sd}`)) || 0));
    const pad = (key: string, label: RowLabel, sds: readonly string[], v: number): void => {
      if (v > 0 || mine?.has(key)) add(key, label, 'space', sds.map((sd) => `padding-${sd}`), v);
    };
    if (pt === pr && pt === pb && pt === pl) pad('padding', 'padding', SIDES, pt);
    else if (pt === pb && pl === pr) {
      pad('padding-block', 'paddingBlock', ['top', 'bottom'], pt);
      pad('padding-inline', 'paddingInline', ['left', 'right'], pl);
    } else {
      pad('padding-top', 'paddingTop', ['top'], pt);
      pad('padding-right', 'paddingRight', ['right'], pr);
      pad('padding-bottom', 'paddingBottom', ['bottom'], pb);
      pad('padding-left', 'paddingLeft', ['left'], pl);
    }
    if (/flex|grid/.test(st.display)) {
      const gap = (p: string): number => {
        const v = st.getPropertyValue(p);
        return v && v !== 'normal' && !v.includes('%') ? round(px(v) || 0) : 0;
      };
      const rg = gap('row-gap');
      const cg = gap('column-gap');
      if (rg === cg) { if (rg > 0 || mine?.has('gap')) add('gap', 'gap', 'space', ['row-gap', 'column-gap'], rg); }
      else {
        if (rg > 0 || mine?.has('row-gap')) add('row-gap', 'rowGap', 'space', ['row-gap'], rg);
        if (cg > 0 || mine?.has('column-gap')) add('column-gap', 'columnGap', 'space', ['column-gap'], cg);
      }
    }
    // A one-off whose row no longer exists in this grouping still shows, so it can be undone.
    if (mine) for (const [key, o] of mine) {
      if (!rows.some((x) => x.key === key)) rows.push({ key, label: o.label, kind: o.kind, props: o.props, value: o.value, token: o.token, overridden: true });
    }
    return rows;
  }

  const pageRec = (el: Element, type: PageEdit['type']): PageRec | undefined => page.find((p) => p.el === el && p.edit.type === type);

  function selectedInfo(): Selected | null {
    if (selected && !selected.isConnected) selected = null;
    if (!selected) return null;
    const el = selected;
    const box = measure.rect(el);
    const plain = el.children.length === 0 && canEditText(el);
    const h = pageRec(el, 'align')?.edit;
    const v = pageRec(el, 'valign')?.edit;
    return {
      el: idOf(el), desc: describe(el), width: Math.round(box.width), height: Math.round(box.height),
      text: plain ? flat(el.textContent) : null,
      rows: rowsFor(el).map(({ props: _props, ...row }) => row),
      align: h && h.type === 'align' ? h.value : null,
      valign: v && v.type === 'valign' ? v.value : null,
      canValign: alignDecls(el, 'v', 'middle').length > 0,
    };
  }

  // ---- one-off edits ----

  function setOneOff(el: Element, key: string, value: TokenValue | null): void {
    let m = oneOffs.get(el);
    const existing = m?.get(key);
    const row = existing ?? rowsFor(el).find((x) => x.key === key);
    if (!row) return;
    const next = value === null ? null : normalise(row.kind, value);
    const tokenValue = row.token ? specs.get(row.token)?.value : undefined;
    if (next === null || (tokenValue !== undefined && sameValue(next, tokenValue))) {
      m?.delete(key);
      if (m && !m.size) oneOffs.delete(el);
    } else {
      if (!m) { m = new Map(); oneOffs.set(el, m); }
      m.set(key, { props: row.props, label: row.label, kind: row.kind, value: next, token: row.token });
    }
    for (const prop of row.props) refresh(el, prop);
  }

  function dropOneOff(el: Element, key: string): OneOffRec | undefined {
    const m = oneOffs.get(el);
    const o = m?.get(key);
    m?.delete(key);
    if (m && !m.size) oneOffs.delete(el);
    return o;
  }

  function promote(el: Element, key: string, mode: 'new' | 'update'): void {
    const o = oneOffs.get(el)?.get(key);
    if (!o) return;
    if (mode === 'update') {
      const spec = o.token ? specs.get(o.token) : undefined;
      if (!spec) return;
      dropOneOff(el, key);
      spec.value = o.value;
      applyToken(spec);
      for (const prop of o.props) refresh(el, prop);
      return;
    }
    const from = o.token ? specs.get(o.token) : undefined;
    const names = [...specs.values()].map((s) => s.name);
    const base = o.kind === 'colour' || o.kind === 'type' ? from?.name ?? null : null;
    const name = base ? newTokenName(base, names) : names.includes(NEW_NAME[o.kind]) ? newTokenName(NEW_NAME[o.kind], names) : NEW_NAME[o.kind];
    const spec = addSpec({ id: `${o.kind}:+${Date.now().toString(36)}${seq}`, kind: o.kind, name, value: o.value }, null, o.token);
    dropOneOff(el, key);
    for (const prop of o.props) rebind(el, prop, spec.id);
    applyToken(spec);
    for (const prop of o.props) refresh(el, prop);
  }

  function removeToken(id: string): void {
    const spec = specs.get(id);
    if (!spec || spec.orig !== null) return;
    const users = [...spec.users].map(([el, props]) => [el, [...props]] as const);
    const back = spec.from && specs.has(spec.from) ? spec.from : null;
    for (const [el, props] of users) for (const prop of props) rebind(el, prop, back);
    specs.delete(id);
    root.style?.removeProperty(spec.varName);
    for (const [el, props] of users) for (const prop of props) refresh(el, prop);
  }

  // ---- text ----

  const canEditText = (el: Element): boolean =>
    !NO_TEXT_EDIT.has(el.localName) && el.namespaceURI !== SVG_NS && hasText(el) && (el.textContent ?? '').length <= TEXT_MAX;

  /** Records (or updates, or drops) the text edit of an element once its text has changed from `from`. */
  function commitText(el: Element, html: string, from: string): void {
    const to = flat(el.textContent);
    const rec = pageRec(el, 'text');
    if (rec && rec.edit.type === 'text') {
      if (to === rec.edit.from) { rec.undo(); page = page.filter((p) => p !== rec); } else rec.edit.to = to;
      return;
    }
    if (to === from) return;
    page.push({ el, edit: { el: idOf(el), type: 'text', target: describe(el), from, to }, undo: () => { el.innerHTML = html; } });
  }

  function beginText(el: Element): void {
    if (editing) finishText();
    editing = { el, attr: el.getAttribute('contenteditable'), html: el.innerHTML, from: flat(el.textContent) };
    el.setAttribute('contenteditable', 'plaintext-only');
    if ((el as HTMLElement).contentEditable !== 'plaintext-only') el.setAttribute('contenteditable', 'true');
    (el as HTMLElement).focus?.();
    const sel = win?.getSelection?.();
    if (sel) {
      const range = doc.createRange();
      range.selectNodeContents(el);
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

  function finishText(): void {
    if (!editing) return;
    const { el, attr, html, from } = editing;
    editing = null;
    if (attr === null) el.removeAttribute('contenteditable'); else el.setAttribute('contenteditable', attr);
    win?.getSelection?.()?.removeAllRanges();
    commitText(el, html, from);
  }

  function setText(el: Element, text: string): void {
    if (el.children.length || !canEditText(el) && !pageRec(el, 'text')) return;
    const html = el.innerHTML;
    const from = flat(el.textContent);
    el.textContent = text.slice(0, TEXT_MAX);
    commitText(el, html, from);
  }

  // ---- alignment ----

  const FLEX = { left: 'flex-start', center: 'center', right: 'flex-end', top: 'flex-start', middle: 'center', bottom: 'flex-end' } as const;
  const GRID = { left: 'start', center: 'center', right: 'end', top: 'start', middle: 'center', bottom: 'end' } as const;

  /** The declarations that align an element on one axis, given how it and its parent lay things out. */
  function alignDecls(el: Element, axis: 'h' | 'v', value: Align | VAlign): [string, string][] {
    const st = measure.style(el);
    const parent = el.parentElement ? measure.style(el.parentElement) : null;
    const flexOf = (s: CSSStyleDeclaration | null): 'row' | 'column' | null =>
      s && /flex/.test(s.display) ? (s.flexDirection.startsWith('column') ? 'column' : 'row') : null;
    const gridOf = (s: CSSStyleDeclaration | null): boolean => !!s && /grid/.test(s.display);
    const self = flexOf(st);
    const out: [string, string][] = [];
    if (axis === 'h') {
      out.push(['text-align', value]);
      // A container aligns what is inside it; anything else is moved within its parent.
      if (self) out.push([self === 'row' ? 'justify-content' : 'align-items', FLEX[value]]);
      else if (gridOf(st)) out.push(['justify-items', GRID[value]]);
      else if (flexOf(parent) === 'column') out.push(['align-self', FLEX[value]]);
      else if (gridOf(parent)) out.push(['justify-self', GRID[value]]);
      else if (flexOf(parent) === 'row') {
        out.push(['margin-left', value === 'left' ? '0' : 'auto'], ['margin-right', value === 'right' ? '0' : 'auto']);
      }
      return out;
    }
    if (self) return [[self === 'row' ? 'align-items' : 'justify-content', FLEX[value]]];
    if (gridOf(st)) return [['align-items', GRID[value]]];
    if (flexOf(parent) === 'row') return [['align-self', FLEX[value]]];
    if (gridOf(parent)) return [['align-self', GRID[value]]];
    if (flexOf(parent) === 'column') {
      return [['margin-top', value === 'top' ? '0' : 'auto'], ['margin-bottom', value === 'bottom' ? '0' : 'auto']];
    }
    if (/^(table-cell|inline)/.test(st.display)) return [['vertical-align', value]];
    return [];
  }

  function setAlign(el: Element, axis: 'h' | 'v', value: Align | VAlign | null): void {
    const type = axis === 'h' ? 'align' : 'valign';
    const axes = forcedAxis.get(el) ?? {};
    const f = forced.get(el) ?? new Map<string, string>();
    for (const prop of axes[axis] ?? []) { f.delete(prop); refresh(el, prop); }
    delete axes[axis];
    page = page.filter((p) => !(p.el === el && p.edit.type === type));
    if (value) {
      const decls = alignDecls(el, axis, value);
      if (decls.length) {
        forced.set(el, f);
        for (const [prop, v] of decls) { f.set(prop, v); refresh(el, prop); }
        axes[axis] = decls.map(([prop]) => prop);
        const edit = (axis === 'h'
          ? { el: idOf(el), type: 'align', target: describe(el), value: value as Align }
          : { el: idOf(el), type: 'valign', target: describe(el), value: value as VAlign }) satisfies PageEdit;
        page.push({ el, edit, undo: () => setAlign(el, axis, null) });
      }
    }
    if (axes.h || axes.v) forcedAxis.set(el, axes); else { forcedAxis.delete(el); forced.delete(el); }
  }

  // ---- drag to move ----

  /** Whether an element's siblings run left to right ('x') or top to bottom ('y'). */
  function axisOf(el: Element): 'x' | 'y' {
    const parent = el.parentElement ? measure.style(el.parentElement) : null;
    if (parent && /grid/.test(parent.display)) return 'x';
    if (parent && /flex/.test(parent.display)) return parent.flexDirection.startsWith('column') ? 'y' : 'x';
    const st = measure.style(el);
    return /^inline/.test(st.display) || (st.cssFloat && st.cssFloat !== 'none') ? 'x' : 'y';
  }

  function findDrop(x: number, y: number): Drop | null {
    if (!drag) return null;
    const hit = doc.elementFromPoint(x, y);
    if (!hit || hit === root || hit === overlay?.host || !doc.body?.contains(hit)) return null;
    let target = hit;
    // Over an element like the dragged one (a card over a card, a link over a link): reorder, never nest.
    const dragged = drag.el;
    for (let cur: Element | null = hit; cur && cur !== doc.body; cur = cur.parentElement) {
      if (cur !== dragged && cur.localName === dragged.localName && cur.getAttribute('class') === dragged.getAttribute('class')) {
        return dropBeside(cur, x, y);
      }
    }
    const kids = Array.from(hit.children).filter((c) => {
      if (c === drag!.el || SKIP.has(c.localName)) return false;
      const b = measure.rect(c);
      return b.width > 0 && b.height > 0;
    });
    if (kids.length) {
      const b = measure.rect(hit);
      const along = axisOf(hit) === 'x' ? [x - b.left, b.width] : [y - b.top, b.height];
      const edge = Math.min(EDGE_MAX, Math.max(EDGE, along[1] * 0.2));
      const nearEdge = hit !== doc.body && (along[0] < edge || along[0] > along[1] - edge);
      if (!nearEdge) {
        let bestD = Infinity;
        for (const c of kids) {
          const cb = measure.rect(c);
          const d = Math.hypot(x - (cb.left + cb.width / 2), y - (cb.top + cb.height / 2));
          if (d < bestD) { bestD = d; target = c; }
        }
      }
    }
    return dropBeside(target, x, y);
  }

  /** Before or after `target`, whichever half the pointer is in, along the direction its siblings run. */
  function dropBeside(target: Element, x: number, y: number): Drop | null {
    if (target === doc.body || !target.parentElement) return null;
    const a = axisOf(target);
    const tb = measure.rect(target);
    const before = a === 'x' ? x < tb.left + tb.width / 2 : y < tb.top + tb.height / 2;
    const line: Box = a === 'x'
      ? { left: before ? tb.left - 5 : tb.left + tb.width + 2, top: tb.top, width: 3, height: tb.height }
      : { left: tb.left, top: before ? tb.top - 5 : tb.top + tb.height + 2, width: tb.width, height: 3 };
    return { parent: target.parentElement, before: before ? target : target.nextElementSibling, line };
  }

  function commitMove(el: Element, parent: Element, before: Element | null): void {
    if (before === el || parent === el || el.contains(parent)) return;
    const existing = pageRec(el, 'move');
    const home = existing ? null : { parent: el.parentNode, next: el.nextElementSibling };
    const was = [el.parentNode, el.nextElementSibling];
    parent.insertBefore(el, before && before.parentNode === parent ? before : null);
    if (was[0] === el.parentNode && was[1] === el.nextElementSibling) return;
    const prev = el.previousElementSibling;
    const next = el.nextElementSibling;
    const place = prev
      ? { where: 'after' as const, anchor: describe(prev) }
      : next ? { where: 'before' as const, anchor: describe(next) } : { where: 'into' as const, anchor: describe(parent) };
    const edit: PageEdit = { el: idOf(el), type: 'move', target: describe(el), ...place };
    if (existing) { existing.edit = edit; return; }
    page.push({
      el, edit,
      undo: () => {
        if (!home?.parent) return;
        home.parent.insertBefore(el, home.next && home.next.parentNode === home.parent ? home.next : null);
      },
    });
  }

  function endDrag(commit: boolean): void {
    if (!drag) return;
    const d = drag;
    drag = null;
    const style = (d.el as Styled).style;
    for (const [prop, v] of d.saved) { if (v) style?.setProperty(prop, v); else style?.removeProperty(prop); }
    overlay?.drop(null);
    if (d.active && commit && drop) commitMove(d.el, drop.parent, drop.before);
    if (d.active) selected = d.el;
    drop = null;
  }

  // ---- undo ----

  function undoPage(el: Element, type: PageEdit['type']): void {
    const rec = pageRec(el, type);
    if (!rec) return;
    rec.undo();
    page = page.filter((p) => p !== rec);
  }

  function reset(): void {
    finishText();
    endDrag(false);
    for (const rec of [...page].reverse()) rec.undo();
    page = [];
    const offs = [...oneOffs].flatMap(([el, m]) => [...m.values()].map((o) => [el, o.props] as const));
    oneOffs.clear();
    for (const [el, props] of offs) for (const prop of props) refresh(el, prop);
    for (const s of [...specs.values()]) if (s.orig === null) removeToken(s.id);
    for (const s of specs.values()) { s.value = s.orig!; applyToken(s); }
  }

  // ---- state for the panel ----

  function edits(): Edits {
    const values: Record<string, TokenValue> = {};
    const added: AddedToken[] = [];
    for (const s of specs.values()) {
      if (s.orig === null) added.push({ id: s.id, kind: s.kind, name: s.name, from: s.from, value: s.value });
      else if (isActive(s)) values[s.id] = s.value;
    }
    const offs: OneOff[] = [];
    for (const [el, m] of oneOffs) {
      for (const [row, o] of m) offs.push({ el: idOf(el), target: describe(el), row, label: o.label, kind: o.kind, value: o.value, token: o.token });
    }
    return { values, added, oneOffs: offs, page: page.map((p) => p.edit) };
  }

  const labelOf = (el: Element): string => {
    const d = describe(el);
    const b = measure.rect(el);
    return `${d.tag}${d.hook} · ${Math.round(b.width)}×${Math.round(b.height)}`;
  };

  function sync(): void {
    if (!overlay) return;
    if (selected && !selected.isConnected) selected = null;
    if (hover && !hover.isConnected) hover = null;
    const show = picking && !drag?.active;
    overlay.select(show && selected ? measure.rect(selected) : null, selected ? labelOf(selected) : undefined);
    overlay.hover(show && hover && hover !== selected ? measure.rect(hover) : null, hover ? labelOf(hover) : undefined);
    overlay.marks(marked.filter((el) => el.isConnected).map((el) => measure.rect(el)));
  }

  function emit(): void {
    sync();
    const uses: Record<string, number> = {};
    for (const s of specs.values()) uses[s.id] = s.users.size;
    send({ picking, selected: picking ? selectedInfo() : null, uses, edits: edits() });
  }

  // ---- pointer and keyboard on the page ----

  const inEditing = (ev: Event): boolean => !!editing && ev.composedPath().includes(editing.el);

  function targetOf(ev: Event): Element | null {
    for (const n of ev.composedPath()) {
      if ((n as Node).nodeType !== 1) continue;
      const el = n as Element;
      return el === overlay?.host || el === root ? null : el;
    }
    return null;
  }

  const swallow = (ev: Event): void => { ev.preventDefault(); ev.stopPropagation(); };

  const listeners: [string, (ev: any) => void][] = [
    ['pointerdown', (ev: PointerEvent) => {
      if (ev.button !== 0 || inEditing(ev)) return;
      swallow(ev);
      if (editing) { finishText(); emit(); }
      const t = targetOf(ev);
      if (t && t !== doc.body) drag = { el: t, x: ev.clientX, y: ev.clientY, active: false, saved: [] };
    }],
    ['pointermove', (ev: PointerEvent) => {
      if (drag) {
        if (!drag.active) {
          if (Math.abs(ev.clientX - drag.x) + Math.abs(ev.clientY - drag.y) < DRAG_START) return;
          drag.active = true;
          hover = null;
          const style = (drag.el as Styled).style;
          for (const [prop, v] of [['opacity', '0.35'], ['pointer-events', 'none']]) {
            drag.saved.push([prop, style?.getPropertyValue(prop) ?? '']);
            style?.setProperty(prop, v, 'important');
          }
          sync();
        }
        ev.preventDefault();
        drop = findDrop(ev.clientX, ev.clientY) ?? drop;
        overlay?.drop(drop?.line ?? null, { x: ev.clientX, y: ev.clientY, text: labelOf(drag.el).split(' · ')[0] });
        return;
      }
      if (inEditing(ev)) return;
      const t = targetOf(ev);
      if (t !== hover) { hover = t; sync(); }
    }],
    ['pointerup', () => {
      if (!drag) return;
      const moved = drag.active;
      endDrag(true);
      if (moved) {
        justDragged = true;
        win?.setTimeout(() => { justDragged = false; }, 0);
        emit();
      }
    }],
    ['pointercancel', () => { if (drag) { endDrag(false); emit(); } }],
    ['mousedown', (ev: MouseEvent) => { if (!inEditing(ev)) swallow(ev); }],
    ['mouseup', (ev: MouseEvent) => { if (!inEditing(ev)) ev.stopPropagation(); }],
    ['click', (ev: MouseEvent) => {
      if (inEditing(ev)) return;
      swallow(ev);
      if (justDragged) { justDragged = false; return; }
      if (editing) finishText();
      selected = targetOf(ev);
      emit();
    }],
    ['dblclick', (ev: MouseEvent) => {
      if (inEditing(ev)) return;
      swallow(ev);
      const t = targetOf(ev);
      if (!t || !canEditText(t)) return;
      selected = t;
      hover = null;
      beginText(t);
      emit();
    }],
    ['keydown', (ev: KeyboardEvent) => {
      if (editing) {
        if (ev.key === 'Escape' || (ev.key === 'Enter' && !ev.shiftKey)) { swallow(ev); finishText(); emit(); }
        return;
      }
      if (ev.key === 'Escape' && selected) { selected = null; emit(); }
    }],
    ['focusout', (ev: FocusEvent) => { if (editing && ev.target === editing.el) { finishText(); emit(); } }],
    ['input', (ev: Event) => { if (inEditing(ev)) sync(); }],
    ['scroll', () => sync()],
    ['resize', () => sync()],
    ['pointerout', (ev: PointerEvent) => { if (!ev.relatedTarget && hover && !drag) { hover = null; sync(); } }],
  ];
  let listening = false;

  function listen(on: boolean): void {
    if (!win || listening === on) return;
    listening = on;
    for (const [type, fn] of listeners) {
      if (on) win.addEventListener(type, fn, { capture: true, passive: type === 'scroll' });
      else win.removeEventListener(type, fn, { capture: true });
    }
  }

  function setPicking(on: boolean): void {
    picking = on;
    if (!on) { finishText(); endDrag(false); hover = null; }
    listen(on);
  }

  function init(list: TokenSpec[], replay?: Pick<Edits, 'values' | 'added'>): void {
    if (specs.size) { reset(); for (const s of specs.values()) root.style?.removeProperty(s.varName); }
    specs.clear();
    binds.clear();
    selected = null;
    for (const s of list) addSpec(s, s.value, null);
    index();
    for (const a of replay?.added ?? []) applyToken(addSpec(a, null, a.from));
    for (const [id, value] of Object.entries(replay?.values ?? {})) {
      const spec = specs.get(id);
      const v = spec && spec.orig !== null ? normalise(spec.kind, value) : null;
      if (spec && v !== null) { spec.value = v; applyToken(spec); }
    }
    overlay ??= createOverlay(doc);
    setPicking(true);
  }

  function handle(cmd: Command): void {
    const el = 'el' in cmd && cmd.el !== null ? byId.get(cmd.el) : undefined;
    switch (cmd.type) {
      case 'init': init(cmd.specs, cmd.replay); break;
      case 'pick': setPicking(cmd.on); break;
      case 'select': selected = el ?? null; break;
      case 'highlight':
        marked = cmd.token ? [...(specs.get(cmd.token)?.users.keys() ?? [])].slice(0, MAX_MARKS) : [];
        sync();
        return;
      case 'setToken': {
        const spec = specs.get(cmd.id);
        const v = spec ? normalise(spec.kind, cmd.value) : null;
        if (spec && v !== null) { spec.value = v; applyToken(spec); }
        break;
      }
      case 'removeToken': removeToken(cmd.id); break;
      case 'setOneOff': if (el) setOneOff(el, cmd.row, cmd.value); break;
      case 'promote': if (el) promote(el, cmd.row, cmd.mode); break;
      case 'setText': if (el) setText(el, cmd.text); break;
      case 'setAlign': if (el) setAlign(el, cmd.axis, cmd.value); break;
      case 'undoPage': if (el) undoPage(el, cmd.edit); break;
      case 'reset': reset(); break;
    }
    emit();
  }

  function destroy(): void {
    reset();
    listen(false);
    for (const s of specs.values()) root.style?.removeProperty(s.varName);
    settle(root);
    specs.clear();
    binds.clear();
    marked = [];
    overlay?.remove();
    overlay = null;
    picking = false;
    selected = null;
  }

  return { handle, destroy };
}
