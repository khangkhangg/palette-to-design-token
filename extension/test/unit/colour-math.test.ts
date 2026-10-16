import { describe, it, expect } from 'vitest';
import {
  parseColour, toHex, composite, toOklab, toOklch, fromOklch, deltaE, luminance, contrastRatio,
  type RGBA,
} from '../../src/analyse/colour-math';

const hex = (s: string) => toHex(parseColour(s)!);
const white: RGBA = { r: 255, g: 255, b: 255, a: 1 };
const black: RGBA = { r: 0, g: 0, b: 0, a: 1 };
const noNaN = (c: RGBA) => [c.r, c.g, c.b, c.a].every((v) => Number.isFinite(v));
const inGamut = (c: RGBA) => [c.r, c.g, c.b].every((v) => v >= 0 && v <= 255 && Number.isInteger(v));

describe('parseColour', () => {
  it('parses rgb, hex and keywords', () => {
    expect(hex('rgb(255, 0, 0)')).toBe('#FF0000');
    expect(hex('rgb(255 0 0 / 50%)')).toBe('#FF0000');
    expect(parseColour('rgb(255 0 0 / 50%)')!.a).toBeCloseTo(0.5);
    expect(hex('rgb(100%, 0%, 0%)')).toBe('#FF0000');
    expect(parseColour('rgba(0,0,0,0.5)')!.a).toBe(0.5);
    expect(hex('#abc')).toBe('#AABBCC');
    expect(parseColour('#abcd')!.a).toBeCloseTo(0xdd / 255);
    expect(hex('#1f5e4b')).toBe('#1F5E4B');
    expect(parseColour('#1f5e4b80')!.a).toBeCloseTo(128 / 255);
  });
  it('transparent is a=0, not null', () => {
    expect(parseColour('transparent')!.a).toBe(0);
    expect(parseColour('rgba(0,0,0,0)')!.a).toBe(0);
  });
  it('unknown is null', () => {
    expect(parseColour('banana')).toBeNull();
    expect(parseColour('')).toBeNull();
    expect(parseColour('#12')).toBeNull();
  });
  it('wide-gamut spaces', () => {
    const o = parseColour('oklch(0.628 0.2577 29.23)')!;
    const e = parseColour('#FF0000')!;
    expect(Math.abs(o.r - e.r)).toBeLessThanOrEqual(1);
    expect(Math.abs(o.g - e.g)).toBeLessThanOrEqual(1);
    expect(Math.abs(o.b - e.b)).toBeLessThanOrEqual(1);
    expect(hex('color(srgb 1 0 0)')).toBe('#FF0000');
    expect(hex('color(srgb-linear 1 0 0)')).toBe('#FF0000');
    const p3 = parseColour('color(display-p3 1 0 0)')!;
    expect(p3.r).toBe(255);
    expect(inGamut(p3)).toBe(true);
    expect(hex('lab(50% 40 30)')).toMatch(/^#[0-9A-F]{6}$/);
    expect(hex('lch(50% 40 30)')).toMatch(/^#[0-9A-F]{6}$/);
    expect(hex('oklab(0.628 0.2249 0.1258)')).toMatch(/^#[0-9A-F]{6}$/);
    expect(hex('lab(100% 0 0)')).toBe('#FFFFFF');
  });
  it('none = 0 and never NaN or out of range', () => {
    expect(hex('oklch(none none none)')).toBe('#000000');
    for (const s of ['oklch(0.9 0.5 200)', 'lab(50% 120 120)', 'color(display-p3 0 1 0)',
      'oklch(1.5 0.4 10)', 'oklch(-1 0.4 10)', 'lab(0% 0 0)', 'oklab(0.5 none 0.4)', 'color(display-p3 2 -1 0.5)']) {
      const c = parseColour(s)!;
      expect(c, s).not.toBeNull();
      expect(noNaN(c), s).toBe(true);
      expect(inGamut(c), s).toBe(true);
    }
  });
});

describe('maths', () => {
  it('contrast', () => {
    expect(contrastRatio(black, white)).toBeCloseTo(21, 2);
    expect(contrastRatio(parseColour('#777777')!, white)).toBeCloseTo(4.48, 2);
    expect(luminance(white)).toBeCloseTo(1, 5);
  });
  it('composite', () => {
    const c = composite({ r: 0, g: 0, b: 0, a: 0.5 }, white);
    expect(c.a).toBe(1);
    expect(Math.abs(c.r - 128)).toBeLessThanOrEqual(1);
    expect(toHex(c)).toMatch(/^#(7F|80)(7F|80)(7F|80)$/);
  });
  it('deltaE', () => {
    expect(deltaE(parseColour('#FF0000')!, parseColour('#FE0000')!)).toBeLessThan(2.5);
    expect(deltaE(parseColour('#FF0000')!, parseColour('#00FF00')!)).toBeGreaterThan(30);
  });
  it('oklab/oklch', () => {
    expect(toOklab(white).L).toBeCloseTo(1, 3);
    expect(toOklch(parseColour('#FF0000')!).h).toBeCloseTo(29.23, 0);
    const c = parseColour('#1F5E4B')!;
    const o = toOklch(c);
    expect(toHex(fromOklch(o.L, o.C, o.h))).toBe('#1F5E4B');
  });
  it('fromOklch reduces chroma into gamut', () => {
    const c = fromOklch(0.7, 0.4, 150);
    expect(inGamut(c)).toBe(true);
    expect(noNaN(c)).toBe(true);
    expect(c.a).toBe(1);
  });
});
