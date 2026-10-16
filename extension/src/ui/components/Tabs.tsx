import type { ComponentChildren } from 'preact';
import { useRef } from 'preact/hooks';

export interface TabDef { id: string; label: string }

export interface TabsProps {
  tabs: TabDef[];
  active: string;
  label: string;
  onChange(id: string): void;
  children: ComponentChildren;
}

/** WAI-ARIA tabs with roving tabindex; arrows follow reading direction, Home/End jump. */
export function Tabs({ tabs, active, label, onChange, children }: TabsProps) {
  const list = useRef<HTMLDivElement>(null);

  const select = (index: number) => {
    const next = tabs[(index + tabs.length) % tabs.length];
    onChange(next.id);
    list.current?.querySelector<HTMLButtonElement>(`#tab-${next.id}`)?.focus();
  };

  const onKeyDown = (e: KeyboardEvent) => {
    const i = tabs.findIndex((tab) => tab.id === active);
    const rtl = getComputedStyle(e.currentTarget as Element).direction === 'rtl';
    const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
    const back = rtl ? 'ArrowRight' : 'ArrowLeft';
    if (e.key === forward) select(i + 1);
    else if (e.key === back) select(i - 1);
    else if (e.key === 'Home') select(0);
    else if (e.key === 'End') select(tabs.length - 1);
    else return;
    e.preventDefault();
  };

  return (
    <>
      <div class="tabs" role="tablist" aria-label={label} ref={list}>
        {tabs.map((tab) => {
          const selected = tab.id === active;
          return (
            <button
              key={tab.id}
              id={`tab-${tab.id}`}
              type="button"
              role="tab"
              class="tab"
              aria-selected={selected}
              aria-controls={selected ? `panel-${tab.id}` : undefined}
              tabIndex={selected ? 0 : -1}
              onClick={() => onChange(tab.id)}
              onKeyDown={onKeyDown}
            >
              {tab.label}
            </button>
          );
        })}
      </div>
      <div class="panel" role="tabpanel" id={`panel-${active}`} aria-labelledby={`tab-${active}`} tabIndex={0}>
        {children}
      </div>
    </>
  );
}
