/** DOM walker: produces raw style samples. Injected into the page as an IIFE, so no runtime imports from the page. */
import { composite, parseColour, toHex, type RGBA } from '../analyse/colour-math';
import type { ColourSample } from '../analyse/colours';
import type { PairSample } from '../analyse/contrast';
import type { TextSample } from '../analyse/scales';

export interface Samples {
  site: string;
  colours: ColourSample[];
  text: TextSample[];
  spacing: number[];
  radius: number[];
  shadows: string[];
  pairs: PairSample[];
  truncated: boolean;
}

export interface Measure {
  rect(el: Element): { width: number; height: number };
  style(el: Element): CSSStyleDeclaration;
}

const DEFAULT_LIMIT = 4000;
const SKIP = new Set(['script', 'style', 'noscript', 'template', 'head', 'meta', 'link']);
const SVG_NS = 'http://www.w3.org/2000/svg';
const WHITE: RGBA = { r: 255, g: 255, b: 255, a: 1 };
const HEADING = /^h[1-6]$/;
const MONO_TAGS = new Set(['code', 'pre', 'kbd', 'samp']);

const defaultMeasure: Measure = {
  rect: (el) => el.getBoundingClientRect(),
  style: (el) => getComputedStyle(el),
};

interface Ctx { interactive: boolean; nav: boolean; heading: boolean; mono: boolean; button: boolean; link: boolean }

const px = (v: string | undefined): number => parseFloat(v ?? '');

const isButton = (el: Element): boolean => {
  const n = el.localName;
  if (n === 'button' || el.getAttribute('role') === 'button') return true;
  if (n === 'input') {
    const t = (el.getAttribute('type') ?? '').toLowerCase();
    return t === 'submit' || t === 'button';
  }
  return false;
};

const isInteractive = (el: Element): boolean =>
  isButton(el) || el.localName === 'summary' || (el.localName === 'a' && el.hasAttribute('href'));

const parentOf = (el: Element): Element | null => {
  if (el.parentElement) return el.parentElement;
  const root = el.getRootNode();
  return root instanceof ShadowRoot ? root.host : null;
};

export function collect(root: Document, measure: Measure = defaultMeasure, limit: number = DEFAULT_LIMIT): Samples {
  const out: Samples = {
    site: (root as { location?: { hostname?: string } }).location?.hostname ?? '',
    colours: [], text: [], spacing: [], radius: [], shadows: [], pairs: [], truncated: false,
  };
  const start = root.body ?? root.documentElement;
  if (!start) return out;

  const styles = new Map<Element, CSSStyleDeclaration>();
  const styleOf = (el: Element) => {
    let s = styles.get(el);
    if (!s) { s = measure.style(el); styles.set(el, s); }
    return s;
  };

  /** Background actually behind an element's text, or null when an image/gradient intervenes. */
  const effectiveBg = (el: Element): string | null => {
    const layers: RGBA[] = [];
    let base: RGBA = WHITE;
    for (let cur: Element | null = el; cur; cur = parentOf(cur)) {
      const s = styleOf(cur);
      if (s.backgroundImage && s.backgroundImage !== 'none') return null;
      const c = parseColour(s.backgroundColor);
      if (!c || c.a <= 0) continue;
      if (c.a >= 1) { base = c; break; }
      layers.push(c);
    }
    let result = base;
    for (let i = layers.length - 1; i >= 0; i--) result = composite(layers[i], result);
    return toHex(result);
  };

  const stack: { el: Element; ctx: Ctx }[] = [];
  const rootCtx: Ctx = { interactive: false, nav: false, heading: false, mono: false, button: false, link: false };
  stack.push({ el: start, ctx: rootCtx });
  let visited = 0;

  while (stack.length) {
    if (visited >= limit) { out.truncated = true; break; }
    const { el, ctx: parent } = stack.pop()!;
    visited++;
    const name = el.localName;
    if (SKIP.has(name)) continue;
    const st = styleOf(el);
    if (st.display === 'none') continue;

    const ctx: Ctx = {
      interactive: parent.interactive || isInteractive(el),
      nav: parent.nav || name === 'nav' || name === 'header',
      heading: parent.heading || HEADING.test(name),
      mono: parent.mono || MONO_TAGS.has(name),
      button: parent.button || isButton(el),
      link: parent.link || name === 'a',
    };

    // children first on the stack (reversed) so the walk is document order, shadow content before light children
    const kids: Element[] = [];
    if (el.shadowRoot) kids.push(...Array.from(el.shadowRoot.children));
    kids.push(...Array.from(el.children));
    for (let i = kids.length - 1; i >= 0; i--) stack.push({ el: kids[i], ctx });

    const rect = measure.rect(el);
    const hidden = st.visibility === 'hidden' || st.visibility === 'collapse' || parseFloat(st.opacity) === 0
      || rect.width === 0 || rect.height === 0;
    if (hidden) continue;

    const area = rect.width * rect.height;
    const { interactive, nav } = ctx;

    // text
    let chars = 0;
    for (const n of Array.from(el.childNodes)) if (n.nodeType === 3) chars += (n.textContent ?? '').trim().length;
    if (chars > 0) {
      const family = st.fontFamily;
      const sizePx = px(st.fontSize);
      const weight = parseInt(st.fontWeight, 10) || 400;
      const lh = px(st.lineHeight);
      out.colours.push({ css: st.color, kind: 'text', weight: chars, interactive, nav });
      out.text.push({
        family, sizePx, weight, lineHeightPx: Number.isFinite(lh) ? lh : null,
        chars, heading: ctx.heading, mono: ctx.mono || /monospace/i.test(family),
      });
      out.pairs.push({
        fg: st.color, bg: effectiveBg(el), chars, sizePx, weight,
        where: ctx.button ? 'buttons' : ctx.link ? 'links' : ctx.heading ? 'headings' : 'body text',
      });
    }

    // background
    const bg = parseColour(st.backgroundColor);
    if (bg && bg.a > 0) out.colours.push({ css: st.backgroundColor, kind: 'bg', weight: area, interactive, nav });

    // border
    if (px(st.borderTopWidth) > 0 && st.borderTopStyle && st.borderTopStyle !== 'none') {
      out.colours.push({ css: st.borderTopColor, kind: 'border', weight: 2 * rect.width + 2 * rect.height, interactive, nav });
    }

    // svg fill
    if (el.namespaceURI === SVG_NS && st.fill && st.fill !== 'none' && parseColour(st.fill)) {
      out.colours.push({ css: st.fill, kind: 'fill', weight: area, interactive, nav });
    }

    // spacing
    for (const p of [st.paddingTop, st.paddingRight, st.paddingBottom, st.paddingLeft]) {
      const v = px(p);
      if (v > 0) out.spacing.push(v);
    }
    for (const m of [st.marginTop, st.marginRight, st.marginBottom, st.marginLeft]) {
      const v = px(m);
      if (v > 0) out.spacing.push(v);
    }
    for (const g of [st.rowGap, st.columnGap]) {
      if (g && g !== 'normal') {
        const v = px(g);
        if (v > 0) out.spacing.push(v);
      }
    }

    // radius
    const r = st.borderTopLeftRadius;
    if (r) {
      const v = px(r);
      if (v > 0) out.radius.push(r.includes('%') && v >= 50 ? 9999 : v);
    }

    // shadow
    if (st.boxShadow && st.boxShadow !== 'none') out.shadows.push(st.boxShadow);
  }
  return out;
}
