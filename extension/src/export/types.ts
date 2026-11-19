export type Part = 'colours' | 'typography' | 'spacing' | 'radius' | 'shadows';
export interface ExportOptions {
  parts: Part[];
  tailwind?: 3 | 4;
  /** Sentences describing the user's text, alignment and position edits; DESIGN.md lists them, other formats ignore them. */
  layout?: string[];
}
