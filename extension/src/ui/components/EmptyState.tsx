import type { ComponentChildren } from 'preact';
import { Icon, type IconName } from './Icon';

export interface EmptyStateProps {
  icon: IconName;
  title: string;
  body?: string;
  /** Announce title and body to assistive tech (for errors). */
  alert?: boolean;
  children?: ComponentChildren;
}

export function EmptyState({ icon, title, body, alert, children }: EmptyStateProps) {
  return (
    <div class="empty">
      <span class="empty-icon"><Icon name={icon} size={20} /></span>
      <div class="empty-text" role={alert ? 'alert' : undefined}>
        <p class="empty-title">{title}</p>
        {body && <p class="empty-body">{body}</p>}
      </div>
      {children}
    </div>
  );
}
