import type { Tokens } from '../../model/tokens';
import { EmptyState } from '../components/EmptyState';
import { RadiusChips, ShadowChips, SpacingBars } from '../components/TokenViews';
import { t } from '../i18n';

export function SpacingTab({ spacing, radius, shadows }: Pick<Tokens, 'spacing' | 'radius' | 'shadows'>) {
  const { base, scale } = spacing;
  if (scale.length === 0 && radius.length === 0 && shadows.length === 0) {
    return <EmptyState icon="info" title={t('spacing.empty')} body={t('spacing.emptyBody')} />;
  }
  return (
    <div class="stack">
      {scale.length > 0 && (
        <section class="group" aria-labelledby="sp-scale">
          <div class="role-head">
            <h2 class="role-name" id="sp-scale">{t('spacing.scale')}</h2>
            <span class="role-var">{base ? t('spacing.base', { n: base }) : t('spacing.noBase')}</span>
          </div>
          <SpacingBars scale={scale} />
        </section>
      )}
      {radius.length > 0 && (
        <section class="group" aria-labelledby="sp-radius">
          <h2 class="role-name" id="sp-radius">{t('spacing.radius')}</h2>
          <RadiusChips radius={radius} />
        </section>
      )}
      {shadows.length > 0 && (
        <section class="group" aria-labelledby="sp-shadow">
          <h2 class="role-name" id="sp-shadow">{t('spacing.shadow')}</h2>
          <ShadowChips shadows={shadows} />
        </section>
      )}
    </div>
  );
}
