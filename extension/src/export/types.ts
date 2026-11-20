export type Part = 'colours' | 'typography' | 'spacing' | 'radius' | 'shadows';
export interface ExportOptions {
  parts: Part[];
  tailwind?: 3 | 4;
  /** Sentences describing the user's text, alignment and position edits; DESIGN.md lists them. */
  layout?: string[];
  /** The same edits, and one-off element edits, as data; tokens.json carries them under $extensions. */
  edits?: ExportEdits;
}

/** How an edited element is told apart: its tag, then its own text, a class or id, or the first text inside it. */
export interface ExportElement { tag: string; text?: string; selector?: string; contains?: string }

export type LayoutAdjustment =
  | { type: 'text'; element: ExportElement; from: string; to: string }
  | { type: 'align'; element: ExportElement; value: 'left' | 'center' | 'right' }
  | { type: 'vertical-align'; element: ExportElement; value: 'top' | 'middle' | 'bottom' }
  | { type: 'move'; element: ExportElement; where: 'before' | 'after' | 'into'; anchor: ExportElement };

/** A style changed on one element only. `token` is the path of the token it used before (`color.primary`), if any. */
export interface ElementEdit { element: ExportElement; property: string; value: string; token: string | null }

export interface ExportEdits { layoutAdjustments: LayoutAdjustment[]; elementEdits: ElementEdit[] }

/** The key this extension's own data sits under in a file's $extensions. */
export const EXTENSION_KEY = 'com.vietnamcos.site-to-design-token';
