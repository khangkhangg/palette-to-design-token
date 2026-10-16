import { useEffect, useRef, useState } from 'preact/hooks';
import type { ColourToken, Role } from '../../model/tokens';
import { Icon } from '../components/Icon';
import { Swatch } from '../components/Swatch';
import { t } from '../i18n';

export const ROLE_ORDER: Role[] = ['primary', 'accent', 'surface', 'text', 'border', 'other'];
const COPIED_MS = 1200;

/** Copies text; `copied` holds the last copied id for 1.2 s. */
export function useCopy() {
  const [copied, setCopied] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  useEffect(() => () => clearTimeout(timer.current), []);
  const copy = async (id: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      return;
    }
    clearTimeout(timer.current);
    setCopied(id);
    timer.current = setTimeout(() => setCopied(null), COPIED_MS);
  };
  return { copied, copy };
}

export function orderedColours(colours: ColourToken[]): ColourToken[] {
  return ROLE_ORDER.flatMap((role) => colours.filter((c) => c.role === role));
}

function shareOf(weight: number, total: number): string {
  const pct = total > 0 ? Math.round((weight / total) * 100) : 0;
  return pct < 1 ? t('colours.lessThanOne') : `${pct}%`;
}

export function ColoursTab({ colours }: { colours: ColourToken[] }) {
  const { copied, copy } = useCopy();
  const total = colours.reduce((sum, c) => sum + c.weight, 0);
  const groups = ROLE_ORDER
    .map((role) => ({ role, items: colours.filter((c) => c.role === role) }))
    .filter((g) => g.items.length > 0);
  const foundLittle = !colours.some((c) => c.role === 'primary' || c.role === 'accent');

  return (
    <div class="colours">
      {foundLittle && (
        <p class="note">
          <Icon name="info" size={14} />
          <span>{colours.length ? t('colours.foundLittle') : t('colours.none')}</span>
        </p>
      )}
      {groups.map(({ role, items }) => (
        <section class="role" key={role} aria-labelledby={`role-${role}`}>
          <div class="role-head">
            <h2 class="role-name" id={`role-${role}`}>{t(`roles.${role}`)}</h2>
            <span class="role-var">--color-{role}</span>
          </div>
          <div class="swatches" style={{ '--cols': Math.min(items.length, 3) }}>
            {items.map((c) => (
              <Swatch
                key={c.name}
                hex={c.hex}
                share={shareOf(c.weight, total)}
                copied={copied === c.name}
                onCopy={(hex) => copy(c.name, hex)}
              />
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}
