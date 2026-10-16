import type { Tokens, TypeToken } from '../../model/tokens';
import { EmptyState } from '../components/EmptyState';
import { t } from '../i18n';

const MAX_SAMPLE_PX = 40;

export function firstFamily(family: string): string {
  const first = family.split(',')[0]?.trim() ?? '';
  return first.replace(/^["']|["']$/g, '');
}

function sampleName(name: string): string {
  const key = `type.sampleName.${name}`;
  const s = t(key);
  return s === key ? name : s;
}

function TypeRow({ token }: { token: TypeToken }) {
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
