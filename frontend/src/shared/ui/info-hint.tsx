import { useId } from 'react';
import { Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';

/**
 * An explanation, kept out of the way.
 *
 * Pages used to open with a paragraph saying what they are for. That sentence is read once, the first week, and then
 * it is the thing every later visit has to scroll past. It lives behind an ⓘ now: hover, focus or tap shows it, and a
 * screen reader gets it as the button's description either way — the text is always in the page, only the pixels are
 * saved.
 */
export function InfoHint({
  text,
  className,
  align = 'start',
}: {
  text: string;
  className?: string | undefined;
  /** Which edge the bubble hangs from — `end` for a hint in a card's far corner, so it opens inward. */
  align?: 'start' | 'end';
}) {
  const { t } = useTranslation();
  const id = useId();

  return (
    <span className={cn('group relative inline-flex align-middle', className)}>
      <button
        type="button"
        aria-label={t('actions.info')}
        aria-describedby={id}
        className="text-content-tertiary hover:text-content-secondary focus-visible:text-content rounded-full p-1 focus-visible:ring-2 focus-visible:ring-[var(--ds-focus-ring)] focus-visible:outline-none"
      >
        <Info aria-hidden className="size-4" />
      </button>
      <span
        id={id}
        role="tooltip"
        className={cn(
          'bg-surface border-line-subtle text-content-secondary invisible absolute top-full z-30 mt-1 w-72 max-w-[80vw] rounded-2xl border p-3 text-sm font-normal opacity-0 shadow-lg transition-opacity group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100',
          align === 'end' ? 'end-0' : 'start-0',
        )}
      >
        {text}
      </span>
    </span>
  );
}
