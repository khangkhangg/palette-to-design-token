import type { Samples } from '../extract/collect';
import type { Tokens } from '../model/tokens';
import { analyseColours } from './colours';
import { analyseContrast } from './contrast';
import { analyseRadius, analyseShadows, analyseSpacing, analyseType } from './scales';

export function buildTokens(s: Samples, now: Date = new Date()): Tokens {
  const { fonts, type } = analyseType(s.text);
  return {
    site: s.site,
    scannedAt: now.toISOString(),
    colours: analyseColours(s.colours),
    fonts,
    type,
    spacing: analyseSpacing(s.spacing),
    radius: analyseRadius(s.radius),
    shadows: analyseShadows(s.shadows),
    contrast: analyseContrast(s.pairs),
  };
}
