import type { Measure } from '../../src/extract/collect';

export interface Spec { w?: number; h?: number; style?: Record<string, string> }

const BASE: Record<string, string> = {
  display: 'block', visibility: 'visible', opacity: '1',
  backgroundColor: 'rgba(0, 0, 0, 0)', backgroundImage: 'none',
  borderTopWidth: '0px', borderTopStyle: 'none', borderTopColor: 'rgb(0, 0, 0)',
  color: 'rgb(0, 0, 0)', fontFamily: 'Arial, sans-serif', fontSize: '16px', fontWeight: '400', lineHeight: 'normal',
  paddingTop: '0px', paddingRight: '0px', paddingBottom: '0px', paddingLeft: '0px',
  marginTop: '0px', marginRight: '0px', marginBottom: '0px', marginLeft: '0px',
  rowGap: 'normal', columnGap: 'normal', borderTopLeftRadius: '0px', boxShadow: 'none', fill: 'rgb(0, 0, 0)',
};

/** jsdom has no layout: look up rect + computed style per element via a spec function, with sane defaults. */
export function stubMeasure(spec: (el: Element) => Spec | undefined = () => undefined): Measure {
  return {
    rect: (el) => {
      const s = spec(el);
      return { width: s?.w ?? 100, height: s?.h ?? 20 };
    },
    style: (el) => ({ ...BASE, ...(spec(el)?.style ?? {}) }) as unknown as CSSStyleDeclaration,
  };
}
