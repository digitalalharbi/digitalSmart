import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';
import { InfoHint } from './info-hint';

/**
 * A labelled set of mutually exclusive choices.
 *
 * A `fieldset` with a `legend` rather than a div with a paragraph: a screen reader then
 * announces the group's name before each option, which is the difference between "28
 * days" and "period: 28 days".
 */
export function ChoiceGroup({
  label,
  hint,
  children,
  className,
}: {
  label: string;
  hint?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <fieldset className={cn('relative flex flex-col gap-2', className)}>
      <legend className="text-content text-sm font-medium">{label}</legend>
      {hint === undefined ? null : (
        <span className="absolute end-0 top-[-0.25rem]">
          <InfoHint text={hint} align="end" />
        </span>
      )}
      {children}
    </fieldset>
  );
}

/**
 * One option in a `ChoiceGroup`.
 *
 * `aria-pressed` rather than a radio, because these change the view immediately rather
 * than being submitted — and because a pressed toggle is what the interface actually is.
 */
export function Choice({
  isSelected,
  onSelect,
  disabled,
  children,
}: {
  isSelected: boolean;
  onSelect: () => void;
  disabled?: boolean;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={isSelected}
      disabled={disabled}
      className={cn(
        'focus-visible:ring-accent/40 rounded-md border px-2.5 py-1 text-xs font-medium',
        'ease-standard transition-colors duration-[var(--ds-duration-fast)] focus-visible:ring-2 focus-visible:outline-none',
        'disabled:cursor-not-allowed disabled:opacity-50',
        isSelected
          ? 'border-accent bg-accent-subtle text-content-brand'
          : 'border-line text-content-secondary hover:bg-surface-hover',
      )}
    >
      {children}
    </button>
  );
}
