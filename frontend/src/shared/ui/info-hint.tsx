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
          /* `hidden`, not `invisible`: an invisible box still takes room, and one near the screen edge widened the page. */
          'bg-surface border-line-subtle text-content-secondary absolute top-full z-30 mt-1 hidden w-72 max-w-[min(18rem,calc(100vw-2rem))] rounded-2xl border p-3 text-sm font-normal shadow-lg group-focus-within:block group-hover:block',
          align === 'end' ? 'end-0' : 'start-0',
        )}
      >
        {text}
      </span>
    </span>
  );
}
