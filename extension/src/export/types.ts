export type Part = 'colours' | 'typography' | 'spacing' | 'radius' | 'shadows';
export interface ExportOptions { parts: Part[]; tailwind?: 3 | 4 }
