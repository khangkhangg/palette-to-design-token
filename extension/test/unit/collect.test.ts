// @vitest-environment jsdom
import { describe, it, expect } from 'vitest';
import { collect } from '../../src/extract/collect';
import { stubMeasure, type Spec } from '../helpers/stub-measure';

const doc = (html: string) => {
  const d = document.implementation.createHTMLDocument('t');
  d.body.innerHTML = html;
  return d;
};
const byId = (m: Record<string, Spec>) => stubMeasure((el) => m[el.id]);

describe('collect visibility', () => {
  it('skips display:none (with subtree), visibility:hidden, opacity:0 and 0x0 rects', () => {
    const d = doc('<p id="a">aaa</p><p id="b">bbb</p><p id="c">ccc</p><p id="d">ddd</p><div id="e"><p id="f">fff</p></div><p id="ok">ok</p>');
    const s = collect(d, byId({
      a: { style: { display: 'none' } },
      b: { style: { visibility: 'hidden' } },
      c: { style: { opacity: '0' } },
      d: { w: 0, h: 0 },
      e: { style: { display: 'none' } },
    }));
    expect(s.text).toHaveLength(1);
    expect(s.colours.filter((c) => c.kind === 'text')).toEqual([expect.objectContaining({ weight: 2 })]);
    expect(s.pairs).toHaveLength(1);
  });

  it('still walks children of an invisible (non display:none) element', () => {
    const d = doc('<div id="w"><p id="k">kid</p></div>');
    const s = collect(d, byId({ w: { style: { opacity: '0' } } }));
    expect(s.text).toHaveLength(1);
  });
});

describe('collect flags and where', () => {
  it('marks buttons and links interactive and nav descendants nav', () => {
    const d = doc('<nav id="n"><a id="l" href="/x">Link</a></nav><button id="b">Buy</button><p id="p">Body</p>');
    const s = collect(d, stubMeasure());
    const text = s.colours.filter((c) => c.kind === 'text');
    expect(text.map((c) => [c.interactive, c.nav])).toEqual([[true, true], [true, false], [false, false]]);
    expect(s.pairs.map((p) => p.where)).toEqual(['links', 'buttons', 'body text']);
  });

  it('classifies role=button, headings, and text inside a button span', () => {
    const d = doc('<div role="button">Go</div><h2>Head</h2><button><span>In</span></button>');
    const s = collect(d, stubMeasure());
    expect(s.pairs.map((p) => p.where)).toEqual(['buttons', 'headings', 'buttons']);
    expect(s.text.map((t) => t.heading)).toEqual([false, true, false]);
  });

  it('flags mono from font-family or code ancestor', () => {
    const d = doc('<p id="m">m</p><pre><span>x</span></pre><p>n</p>');
    const s = collect(d, byId({ m: { style: { fontFamily: 'ui-monospace, monospace' } } }));
    expect(s.text.map((t) => t.mono)).toEqual([true, true, false]);
  });
});

describe('collect effective background', () => {
  it('composites translucent layers down to the first opaque ancestor', () => {
    const d = doc('<div id="o"><div id="t"><p id="p">hi</p></div></div>');
    const s = collect(d, byId({
      o: { style: { backgroundColor: 'rgb(0, 0, 255)' } },
      t: { style: { backgroundColor: 'rgba(255, 0, 0, 0.5)' } },
    }));
    expect(s.pairs[0].bg).toBe('#800080');
  });

  it('returns null when a background-image sits before an opaque colour', () => {
    const d = doc('<div id="o"><div id="g"><p>hi</p></div></div>');
    const s = collect(d, byId({
      o: { style: { backgroundColor: 'rgb(255, 255, 255)' } },
      g: { style: { backgroundImage: 'linear-gradient(red, blue)' } },
    }));
    expect(s.pairs[0].bg).toBeNull();
  });

  it('falls back to white when no opaque colour is found', () => {
    const s = collect(doc('<p>hi</p>'), stubMeasure());
    expect(s.pairs[0].bg).toBe('#FFFFFF');
  });
});

describe('collect samples', () => {
  it('records bg, border, spacing, radius and shadow values', () => {
    const d = doc('<div id="b"></div>');
    const s = collect(d, byId({
      b: { w: 10, h: 5, style: {
        backgroundColor: 'rgb(10, 20, 30)', borderTopWidth: '2px', borderTopStyle: 'solid', borderTopColor: 'rgb(1, 2, 3)',
        paddingTop: '8px', paddingLeft: '16px', marginTop: '-4px', marginBottom: '24px', rowGap: '12px',
        borderTopLeftRadius: '50%', boxShadow: '0 1px 2px rgba(0,0,0,.2)',
      } },
    }));
    expect(s.colours).toEqual([
      expect.objectContaining({ kind: 'bg', css: 'rgb(10, 20, 30)', weight: 50 }),
      expect.objectContaining({ kind: 'border', css: 'rgb(1, 2, 3)', weight: 30 }),
    ]);
    expect(s.spacing).toEqual(expect.arrayContaining([8, 16, 24, 12]));
    expect(s.spacing).not.toContain(-4);
    expect(s.radius).toContain(9999);
    expect(s.shadows).toEqual(['0 1px 2px rgba(0,0,0,.2)']);
  });

  it('records svg fills by area', () => {
    const d = doc('<svg><rect id="r"></rect></svg>');
    const s = collect(d, stubMeasure((el) => (el.id === 'r' ? { w: 4, h: 5, style: { fill: 'rgb(9, 9, 9)' } } : { style: { fill: 'none' } })));
    expect(s.colours.filter((c) => c.kind === 'fill')).toEqual([expect.objectContaining({ css: 'rgb(9, 9, 9)', weight: 20 })]);
  });

  it('samples fill on svg shapes only, not on the svg and g containers that merely inherit it', () => {
    // Every svg element has a computed fill (default black, inherited): sampling containers counted
    // black by the icon's whole box even when the only painted shape is green (T14 light-marketing).
    const d = doc('<svg id="s"><g id="g"><path id="p"></path></g></svg>');
    const s = collect(d, byId({
      s: { w: 24, h: 24 }, g: { w: 20, h: 20 },
      p: { w: 10, h: 10, style: { fill: 'rgb(31, 94, 75)' } },
    }));
    expect(s.colours.filter((c) => c.kind === 'fill')).toEqual([expect.objectContaining({ css: 'rgb(31, 94, 75)', weight: 100 })]);
  });

  it('records a border drawn on any side, not only the top', () => {
    // Row separators and sidebar rules are bottom / inline-end borders (T14 dark-app).
    const d = doc('<div id="b"></div><div id="e"></div>');
    const s = collect(d, byId({
      b: { w: 10, h: 5, style: { borderBottomWidth: '1px', borderBottomStyle: 'solid', borderBottomColor: 'rgb(48, 54, 61)' } },
      e: { w: 10, h: 5, style: { borderLeftWidth: '1px', borderLeftStyle: 'solid', borderLeftColor: 'rgb(1, 2, 3)' } },
    }));
    expect(s.colours.filter((c) => c.kind === 'border')).toEqual([
      expect.objectContaining({ css: 'rgb(48, 54, 61)', weight: 30 }),
      expect.objectContaining({ css: 'rgb(1, 2, 3)', weight: 30 }),
    ]);
  });

  it('ignores percentage gaps and percentage radii under 50%, which are not px values', () => {
    // getComputedStyle returns gap and border-radius percentages as written ("2%", "6%"); parseFloat read them as px.
    const d = doc('<div id="g"></div><div id="r"></div>');
    const s = collect(d, byId({
      g: { style: { rowGap: '2%', columnGap: '2%', paddingTop: '8px' } },
      r: { style: { borderTopLeftRadius: '6%' } },
    }));
    expect(s.spacing).toEqual([8]);
    expect(s.radius).toEqual([]);
  });

  it('skips auto margins, whose px value is centring slack and not spacing', () => {
    // getComputedStyle reports margin: 0 auto as the used px (T14 arabic-rtl: 100px on a 1280px viewport).
    const d = doc('<div id="w"></div>');
    const base = byId({ w: { style: { marginLeft: '100px', marginRight: '100px', marginTop: '16px' } } });
    const s = collect(d, { ...base, autoMargin: (_el, side) => side === 'left' || side === 'right' });
    expect(s.spacing).toEqual([16]);
  });

  it('uses the page hostname as site', () => {
    const d = doc('<p>x</p>');
    const withLoc = { body: d.body, documentElement: d.documentElement, location: { hostname: 'example.com' } } as unknown as Document;
    expect(collect(withLoc, stubMeasure()).site).toBe('example.com');
    expect(collect(doc('<p>x</p>'), stubMeasure()).site).toBe('');
  });
});

describe('collect traversal', () => {
  it('stops at the limit and sets truncated', () => {
    const d = doc('<i></i>'.repeat(5000));
    let visited = 0;
    const m = stubMeasure();
    const counting = { rect: m.rect, style: (el: Element) => { visited++; return m.style(el); } };
    const s = collect(d, counting, 4000);
    expect(visited).toBe(4000);
    expect(s.truncated).toBe(true);
    expect(collect(doc('<i></i>'), stubMeasure()).truncated).toBe(false);
  });

  it('walks open shadow roots and skips script/style', () => {
    const d = doc('<div id="h"></div><script>x</script><style>y</style>');
    const root = d.getElementById('h')!.attachShadow({ mode: 'open' });
    root.innerHTML = '<p>shadow</p>';
    const s = collect(d, stubMeasure());
    expect(s.text).toHaveLength(1);
    expect(s.text[0].chars).toBe(6);
  });
});
