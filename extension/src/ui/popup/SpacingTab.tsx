import type { Tokens } from '../../model/tokens';
import { EmptyState } from '../components/EmptyState';
import { t } from '../i18n';

const MAX_BAR_PX = 64;

export function SpacingTab({ spacing, radius, shadows }: Pick<Tokens, 'spacing' | 'radius' | 'shadows'>) {
  const { base, scale } = spacing;
  if (scale.length === 0 && radius.length === 0 && shadows.length === 0) {
    return <EmptyState icon="info" title={t('spacing.empty')} body={t('spacing.emptyBody')} />;
  }
  const max = Math.max(...scale, 1);
  return (
    <div class="stack">
      {scale.length > 0 && (
        <section class="group" aria-labelledby="sp-scale">
          <div class="role-head">
            <h2 class="role-name" id="sp-scale">{t('spacing.scale')}</h2>
            <span class="role-var">{base ? t('spacing.base', { n: base }) : t('spacing.noBase')}</span>
          </div>
          <ul class="bars">
            {scale.map((v) => (
              <li class="bar" key={v}>
                <span class="bar-fill" style={{ blockSize: `${Math.max(2, Math.round((v / max) * MAX_BAR_PX))}px` }} />
                <span class="bar-label">{v}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {radius.length > 0 && (
        <section class="group" aria-labelledby="sp-radius">
          <h2 class="role-name" id="sp-radius">{t('spacing.radius')}</h2>
          <ul class="chips">
            {radius.map((r) => (
              <li class="radius-chip" key={r}>
                <span class="radius-box" style={{ borderStartStartRadius: `${Math.min(r, 20)}px` }} />
                <span class="bar-label">{r >= 999 ? t('spacing.full') : t('spacing.px', { n: r })}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {shadows.length > 0 && (
        <section class="group" aria-labelledby="sp-shadow">
          <h2 class="role-name" id="sp-shadow">{t('spacing.shadow')}</h2>
          <ul class="chips">
            {shadows.map((s, i) => (
              <li class="shadow-chip" key={s}>
                <span class="shadow-box" style={{ boxShadow: s }} />
                <span class="bar-label">{i + 1}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
