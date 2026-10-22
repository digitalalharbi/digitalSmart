import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';

export type TabItem<T extends string> = { id: T; label: string; badge?: ReactNode };

/**
 * A row of tabs that switches what a page shows without leaving it.
 *
 * `role="tablist"` with arrow-key movement (and Home/End), the roving tab stop a keyboard user expects, and the active
 * tab announced as selected. Only the active tab's panel is rendered by the caller; this is the control, not the content.
 * Ordered in reading order: the arrows follow the document's direction, so in Arabic the right arrow moves to the
 * previous tab, as the eye does.
 */
export function TabList<T extends string>({
  tabs,
  active,
  onChange,
  label,
  idPrefix,
}: {
  tabs: Array<TabItem<T>>;
  active: T;
  onChange: (id: T) => void;
  label: string;
  idPrefix: string;
}) {
  const move = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
    const step =
      event.key === 'ArrowRight' ? (rtl ? -1 : 1) : event.key === 'ArrowLeft' ? (rtl ? 1 : -1) : 0;
    const target = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : index + step;

    if (step === 0 && event.key !== 'Home' && event.key !== 'End') {
      return;
    }

    event.preventDefault();
    const next = tabs[(target + tabs.length) % tabs.length];

    if (next !== undefined) {
      onChange(next.id);
      document.getElementById(`${idPrefix}-tab-${next.id}`)?.focus();
    }
  };

  return (
    <div
      role="tablist"
      aria-label={label}
      className="bg-surface-sunken flex max-w-full flex-wrap gap-1 self-start rounded-full p-1"
    >
      {tabs.map((tab, index) => {
        const selected = tab.id === active;

        return (
          <button
            key={tab.id}
            id={`${idPrefix}-tab-${tab.id}`}
            type="button"
            role="tab"
            aria-selected={selected}
            aria-controls={`${idPrefix}-panel`}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(tab.id)}
            onKeyDown={(event) => move(event, index)}
            className={cn(
              'focus-visible:ring-accent/40 inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none',
              selected
                ? 'bg-surface text-content shadow-xs'
                : 'text-content-secondary hover:text-content',
            )}
          >
            {tab.label}
            {tab.badge}
          </button>
        );
      })}
    </div>
  );
}
