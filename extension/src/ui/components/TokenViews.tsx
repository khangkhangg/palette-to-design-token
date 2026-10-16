import type { TypeToken } from '../../model/tokens';
import { t } from '../i18n';

const MAX_SAMPLE_PX = 40;
const MAX_BAR_PX = 64;

export function firstFamily(family: string): string {
  const first = family.split(',')[0]?.trim() ?? '';
  return first.replace(/^["']|["']$/g, '');
}

function sampleName(name: string): string {
  const key = `type.sampleName.${name}`;
  const s = t(key);
  return s === key ? name : s;
}

export function TypeRow({ token }: { token: TypeToken }) {
  const family = firstFamily(token.family);
  const lineHeight = token.lineHeightPx === null ? '–' : `${token.lineHeightPx}`;
  return (
    <li class="type-row">
      <span class="type-name">{token.name}</span>
      <span
        class="type-sample"
        style={{
          fontFamily: token.family,
          fontSize: `${Math.min(token.sizePx, MAX_SAMPLE_PX)}px`,
          fontWeight: token.weight,
        }}
      >
        {t('type.sample', { name: sampleName(token.name) })}
      </span>
      <span class="type-meta">
        {t('type.meta', { family, weight: token.weight, size: token.sizePx, lineHeight })}
      </span>
    </li>
  );
}

export function SpacingBars({ scale }: { scale: number[] }) {
  const max = Math.max(...scale, 1);
  return (
    <ul class="bars">
      {scale.map((v) => (
        <li class="bar" key={v}>
          <span class="bar-fill" style={{ blockSize: `${Math.max(2, Math.round((v / max) * MAX_BAR_PX))}px` }} />
          <span class="bar-label">{v}</span>
        </li>
      ))}
    </ul>
  );
}

export function RadiusChips({ radius }: { radius: number[] }) {
  return (
    <ul class="chips">
      {radius.map((r) => (
        <li class="radius-chip" key={r}>
          <span class="radius-box" style={{ borderStartStartRadius: `${Math.min(r, 20)}px` }} />
          <span class="bar-label">{r >= 999 ? t('spacing.full') : t('spacing.px', { n: r })}</span>
        </li>
      ))}
    </ul>
  );
}

export function ShadowChips({ shadows }: { shadows: string[] }) {
  return (
    <ul class="chips">
      {shadows.map((s, i) => (
        <li class="shadow-chip" key={s}>
          <span class="shadow-box" style={{ boxShadow: s }} />
          <span class="bar-label">{i + 1}</span>
        </li>
      ))}
    </ul>
  );
}
