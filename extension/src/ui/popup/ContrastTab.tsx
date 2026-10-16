import type { ContrastPair, Grade, Tokens } from '../../model/tokens';
import { EmptyState } from '../components/EmptyState';
import { Icon } from '../components/Icon';
import { t } from '../i18n';

/** Floors to 2 decimals so a failing ratio is never shown as a passing number. */
export function formatRatio(ratio: number): string {
  return (Math.floor(ratio * 100 + 1e-9) / 100).toFixed(2);
}

const PASSING: Grade[] = ['AAA', 'AA', 'AA-large'];
const isPass = (g: Grade) => PASSING.includes(g);

function ratioText(ratio: number | null): string {
  return ratio === null ? '–' : t('contrast.ratio', { ratio: formatRatio(ratio) });
}

function Chip({ fg, bg }: { fg: string; bg: string }) {
  const none = bg === 'none';
  return (
    <span
      class={none ? 'cchip cchip-none' : 'cchip'}
      style={{ color: fg, ...(none ? {} : { background: bg }) }}
      aria-hidden="true"
    >
      Aa
    </span>
  );
}

function FixCard({ pair, pro, onUnlock }: { pair: ContrastPair; pro: boolean; onUnlock(): void }) {
  const where = t(`contrast.where.${pair.where}`).toLowerCase();
  return (
    <div class={pro ? 'fix' : 'fix fix-locked'}>
      <div class="fix-head">
        <Icon name="wand" />
        <span>{t('contrast.fixTitle', { where })}</span>
        {!pro && <span class="pro-pill">{t('popup.pro')}</span>}
      </div>
      {!pro && (
        <div class="fix-body">
          <Icon name="lock" />
          <span class="fix-lock-text">{t('contrast.fixLocked')}</span>
          <button type="button" class="btn btn-small" onClick={onUnlock}>{t('contrast.unlock')}</button>
        </div>
      )}
      {pro && pair.fix && (
        <div class="fix-body">
          <span class="fix-btn" style={{ color: pair.fg, background: pair.bg }}>{t('contrast.sampleButton')}</span>
          <Icon name="arrow" size={16} />
          <span class="fix-btn" style={{ color: pair.fix.fg, background: pair.fix.bg }}>{t('contrast.sampleButton')}</span>
          <span class="fix-ratio">{t('contrast.fixedRatio', { ratio: formatRatio(pair.fix.ratio) })}</span>
        </div>
      )}
    </div>
  );
}

export interface ContrastTabProps {
  contrast: Tokens['contrast'];
  pro: boolean;
  onUnlock?(): void;
}

export function ContrastTab({ contrast, pro, onUnlock = () => {} }: ContrastTabProps) {
  if (contrast.length === 0) {
    return <EmptyState icon="info" title={t('contrast.empty')} body={t('contrast.emptyBody')} />;
  }
  const pass = contrast.filter((p) => isPass(p.grade)).length;
  const fail = contrast.filter((p) => p.grade === 'fail').length;
  const unknown = contrast.filter((p) => p.grade === 'unknown').length;
  return (
    <div class="stack">
      <div class="summary">
        <div class="sum sum-pass"><span class="sum-n">{pass}</span><span class="sum-l">{t('contrast.pass')}</span></div>
        <div class="sum sum-fail"><span class="sum-n">{fail}</span><span class="sum-l">{t('contrast.fail')}</span></div>
        {unknown > 0 && (
          <div class="sum sum-unknown"><span class="sum-n">{unknown}</span><span class="sum-l">{t('contrast.unknown')}</span></div>
        )}
      </div>
      <ul class="pairs">
        {contrast.map((p) => (
          <li class="pair-item" key={`${p.fg}-${p.bg}-${p.where}`}>
            <div class="pair">
              <Chip fg={p.fg} bg={p.bg} />
              <div class="pair-text">
                <span class="pair-colours">
                  {p.fg} on {p.bg === 'none' ? t('contrast.imageOrGradient') : p.bg}
                </span>
                <span class="pair-where">{t(`contrast.where.${p.where}`)} · {t('contrast.uses', { n: p.uses })}</span>
              </div>
              <div class="pair-score">
                <span class="pair-ratio">{ratioText(p.ratio)}</span>
                <span class={`grade grade-${isPass(p.grade) ? 'pass' : p.grade}`}>{t(`contrast.grade.${p.grade}`)}</span>
              </div>
            </div>
            {p.grade === 'fail' && <FixCard pair={p} pro={pro} onUnlock={onUnlock} />}
          </li>
        ))}
      </ul>
    </div>
  );
}
