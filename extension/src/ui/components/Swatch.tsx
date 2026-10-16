import { t } from '../i18n';

export interface SwatchProps {
  hex: string;
  share: string;
  copied: boolean;
  /** The last copy was refused by the clipboard. */
  failed?: boolean;
  onCopy(hex: string): void;
}

export function Swatch({ hex, share, copied, failed = false, onCopy }: SwatchProps) {
  return (
    <button
      type="button"
      class="swatch"
      aria-label={t('colours.copy', { hex, share })}
      onClick={() => onCopy(hex)}
    >
      <span class="swatch-chip" style={{ background: hex }} />
      <span class="swatch-meta">
        <span class={copied ? 'swatch-hex is-copied' : failed ? 'swatch-hex is-failed' : 'swatch-hex'} aria-live="polite">
          {copied ? t('colours.copied') : failed ? t('colours.copyFailed') : hex}
        </span>
        <span class="swatch-share">{share}</span>
      </span>
    </button>
  );
}
