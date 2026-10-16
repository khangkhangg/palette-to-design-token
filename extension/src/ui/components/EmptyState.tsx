import type { ComponentChildren } from 'preact';
import { Icon, type IconName } from './Icon';

export interface EmptyStateProps {
  icon: IconName;
  title: string;
  body?: string;
  children?: ComponentChildren;
}

export function EmptyState({ icon, title, body, children }: EmptyStateProps) {
  return (
    <div class="empty">
      <span class="empty-icon"><Icon name={icon} size={20} /></span>
      <p class="empty-title">{title}</p>
      {body && <p class="empty-body">{body}</p>}
      {children}
    </div>
  );
}
