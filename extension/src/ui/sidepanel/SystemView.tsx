import type { ColourToken, Role, Tokens } from '../../model/tokens';
import { Icon } from '../components/Icon';
import { RadiusChips, ShadowChips, SpacingBars, TypeRow } from '../components/TokenViews';
import { orderedColours, ROLE_ORDER } from '../popup/ColoursTab';
import { t } from '../i18n';
import { AiSection } from './AiSection';

/** First (heaviest) colour of each role, in role order. */
function roleLeaders(colours: ColourToken[]): { role: Role; colour: ColourToken }[] {
  return ROLE_ORDER.flatMap((role) => {
    const colour = colours.find((c) => c.role === role);
    return colour ? [{ role, colour }] : [];
  });
}

export interface SystemViewProps {
  tokens: Tokens;
  onExport(): void;
}

export function SystemView({ tokens, onExport }: SystemViewProps) {
  const { colours, type, spacing, radius, shadows } = tokens;
  const strip = orderedColours(colours);
  return (
    <>
      <div class="sp-body">
        {colours.length > 0 && (
          <section class="sp-section" aria-labelledby="sp-colours">
            <h2 class="sp-h" id="sp-colours">{t('panel.colours')}</h2>
            <div class="strip" aria-hidden="true">
              {strip.map((c) => (
                <span class="strip-seg" key={c.name} style={{ flexGrow: c.weight, backgroundColor: c.hex }} />
              ))}
            </div>
            <ul class="legend" aria-label={t('panel.colourRoles')}>
              {roleLeaders(colours).map(({ role, colour }) => (
                <li class="legend-item" key={role}>
                  <span class="legend-dot" style={{ backgroundColor: colour.hex }} aria-hidden="true" />
                  <span>{t(`roles.${role}`)}</span>
                  <span class="legend-hex">{colour.hex}</span>
                </li>
              ))}
            </ul>
          </section>
        )}
        {type.length > 0 && (
          <section class="sp-section" aria-labelledby="sp-type">
            <h2 class="sp-h" id="sp-type">{t('panel.typography')}</h2>
            <ul class="sp-type">
              {type.map((tok) => <TypeRow key={tok.name} token={tok} />)}
            </ul>
          </section>
        )}
        {spacing.scale.length > 0 && (
          <section class="sp-section" aria-labelledby="sp-spacing">
            <h2 class="sp-h" id="sp-spacing">
              {t('panel.spacing')}
              {spacing.base && <span class="sp-h-note"> · {t('spacing.base', { n: spacing.base })}</span>}
            </h2>
            <SpacingBars scale={spacing.scale} />
          </section>
        )}
        {(radius.length > 0 || shadows.length > 0) && (
          <div class="sp-pair">
            {radius.length > 0 && (
              <section class="sp-section" aria-labelledby="sp-radius">
                <h2 class="sp-h" id="sp-radius">{t('panel.radius')}</h2>
                <RadiusChips radius={radius} />
              </section>
            )}
            {shadows.length > 0 && (
              <section class="sp-section" aria-labelledby="sp-shadows">
                <h2 class="sp-h" id="sp-shadows">{t('panel.shadows')}</h2>
                <ShadowChips shadows={shadows} />
              </section>
            )}
          </div>
        )}
        <AiSection key={tokens.site} tokens={tokens} />
      </div>
      <footer class="sp-footer">
        <button type="button" class="sp-export" onClick={onExport}>
          <Icon name="download" size={16} />
          {t('panel.exportDesignSystem')}
        </button>
        <span class="sp-caption">{t('panel.exportFormats')}</span>
      </footer>
    </>
  );
}
