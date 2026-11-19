/** Shared by the on-page editor (edit.js) and the side panel: token specs, edits, and the messages between them. No DOM. */

export type TokenKind = 'colour' | 'type' | 'space' | 'radius';
/** A colour is a hex string; type size, spacing and radius are px numbers. */
export type TokenValue = string | number;

/** One editable token as the panel hands it to the page. `id` is stable for the session; `value` is the scanned value. */
export interface TokenSpec { id: string; kind: TokenKind; name: string; value: TokenValue }

/** A token the user created from a one-off edit. `from` is the token the element used before, if any. */
export interface AddedToken { id: string; kind: TokenKind; name: string; from: string | null; value: TokenValue }

export type RowLabel =
  | 'color' | 'background' | 'border' | 'fill' | 'size' | 'radius'
  | 'padding' | 'paddingBlock' | 'paddingInline' | 'paddingTop' | 'paddingRight' | 'paddingBottom' | 'paddingLeft'
  | 'gap' | 'rowGap' | 'columnGap';

/** How an element is named to the user and in DESIGN.md. */
export interface ElDesc {
  tag: string;
  /** The element's own text, shortened; empty when it has none. */
  text: string;
  /** `#id` or `.first-class`, when it has one. */
  hook: string;
  /** For an element without text of its own: the first text inside it (a card's heading), shortened. */
  inside: string;
}

/** One editable style of the selected element. `token` is the token it uses, or null when no token matches. */
export interface Row { key: string; label: RowLabel; kind: TokenKind; value: TokenValue; token: string | null; overridden: boolean }

export interface OneOff { el: number; target: ElDesc; row: string; label: RowLabel; kind: TokenKind; value: TokenValue; token: string | null }

export type Align = 'left' | 'center' | 'right';
export type VAlign = 'top' | 'middle' | 'bottom';

export type PageEdit =
  | { el: number; type: 'text'; target: ElDesc; from: string; to: string }
  | { el: number; type: 'align'; target: ElDesc; value: Align }
  | { el: number; type: 'valign'; target: ElDesc; value: VAlign }
  | { el: number; type: 'move'; target: ElDesc; where: 'before' | 'after' | 'into'; anchor: ElDesc };

export interface Edits {
  /** Scanned tokens whose value changed: id -> new value. */
  values: Record<string, TokenValue>;
  added: AddedToken[];
  oneOffs: OneOff[];
  page: PageEdit[];
}

export const NO_EDITS = (): Edits => ({ values: {}, added: [], oneOffs: [], page: [] });

export interface Selected {
  el: number;
  desc: ElDesc;
  width: number;
  height: number;
  /** The element's text when it can be rewritten from the panel (no child elements); null otherwise. */
  text: string | null;
  rows: Row[];
  align: Align | null;
  valign: VAlign | null;
  /** False when the page's layout gives vertical alignment nothing to act on. */
  canValign: boolean;
}

export interface EditorState {
  picking: boolean;
  selected: Selected | null;
  /** Token id -> number of elements on the page that use it. */
  uses: Record<string, number>;
  edits: Edits;
}

export type Command =
  | { type: 'init'; specs: TokenSpec[]; replay?: Pick<Edits, 'values' | 'added'> }
  | { type: 'pick'; on: boolean }
  | { type: 'select'; el: number | null }
  | { type: 'highlight'; token: string | null }
  | { type: 'setToken'; id: string; value: TokenValue }
  | { type: 'removeToken'; id: string }
  | { type: 'setOneOff'; el: number; row: string; value: TokenValue | null }
  | { type: 'promote'; el: number; row: string; mode: 'new' | 'update' }
  | { type: 'setText'; el: number; text: string }
  | { type: 'setAlign'; el: number; axis: 'h'; value: Align | null }
  | { type: 'setAlign'; el: number; axis: 'v'; value: VAlign | null }
  | { type: 'undoPage'; el: number; edit: PageEdit['type'] }
  | { type: 'reset' };

export type PageMessage = { type: 'state'; state: EditorState };

export const PORT_NAME = 'sdt-edit';

/** A name for a new token that no existing token has: `accent-2`, `accent-3`, … or `custom`, `custom-2` with no base. */
export function newTokenName(base: string | null, taken: Iterable<string>): string {
  const used = new Set(taken);
  const stem = (base ?? 'custom').replace(/-\d+$/, '');
  if (base === null && !used.has(stem)) return stem;
  for (let n = 2; ; n++) if (!used.has(`${stem}-${n}`)) return `${stem}-${n}`;
}

export const sameValue = (a: TokenValue, b: TokenValue): boolean =>
  typeof a === 'string' && typeof b === 'string' ? a.toLowerCase() === b.toLowerCase() : a === b;
