import { t } from '../i18n';

export interface SwatchProps {
  hex: string;
  share: string;
  copied: boolean;
  onCopy(hex: string): void;
}

export function Swatch({ hex, share, copied, onCopy }: SwatchProps) {
  return (
    <button
      type="button"
      class="swatch"
      aria-label={t('colours.copy', { hex, share })}
      onClick={() => onCopy(hex)}
    >
      <span class="swatch-chip" style={{ background: hex }} />
      <span class="swatch-meta">
        <span class={copied ? 'swatch-hex is-copied' : 'swatch-hex'} aria-live="polite">
          {copied ? t('colours.copied') : hex}
        </span>
        <span class="swatch-share">{share}</span>
      </span>
    </button>
  );
}
