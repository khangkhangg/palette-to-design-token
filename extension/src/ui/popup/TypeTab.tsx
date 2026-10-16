import type { Tokens } from '../../model/tokens';
import { EmptyState } from '../components/EmptyState';
import { firstFamily, TypeRow } from '../components/TokenViews';
import { t } from '../i18n';

export function TypeTab({ fonts, type }: Pick<Tokens, 'fonts' | 'type'>) {
  if (fonts.length === 0 && type.length === 0) {
    return <EmptyState icon="info" title={t('type.empty')} body={t('type.emptyBody')} />;
  }
  return (
    <div class="stack">
      {fonts.length > 0 && (
        <section class="group" aria-labelledby="type-fonts">
          <h2 class="role-name" id="type-fonts">{t('type.fonts')}</h2>
          <ul class="font-list">
            {fonts.map((f) => (
              <li class="font-row" key={f.name}>
                <span class="font-role">{t(`type.fontRole.${f.name}`)}</span>
                <span class="font-family" style={{ fontFamily: f.family }}>{firstFamily(f.family)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {type.length > 0 && (
        <section class="group" aria-labelledby="type-scale">
          <h2 class="role-name" id="type-scale">{t('type.scale')}</h2>
          <ul class="type-list">
            {type.map((tok) => <TypeRow key={tok.name} token={tok} />)}
          </ul>
        </section>
      )}
    </div>
  );
}
