import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import { cn } from '@/shared/lib/cn';

/**
 * A period-over-period change, as a pill.
 *
 * **Colour follows whether the change is good, not whether it is up.** The server decides that from the
 * metric's own direction, because CPA falling twenty per cent is a success and spend falling twenty per cent
 * may be a campaign that stopped delivering — and a product that paints every downward arrow red teaches its
 * readers to ignore the colour.
 *
 * When nobody has decided — `isGood` is null — the pill is neutral. A guess here would be a claim about the
 * business that nothing in the data supports.
 */
export function TrendBadge({
  change,
  ratio,
  isGood,
  className,
}: {
  /** The formatted change, e.g. "+8.4%". */
  change: string;
  ratio: number | null;
  isGood: boolean | null;
  className?: string | undefined;
}) {
  const Icon =
    ratio !== null && ratio > 0
      ? ArrowUpRight
      : ratio !== null && ratio < 0
        ? ArrowDownRight
        : Minus;

  return (
    <span
      className={cn(
        'ds-numeric inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium',
        isGood === true && 'bg-success-surface text-success',
        isGood === false && 'bg-danger-surface text-danger',
        isGood === null && 'bg-surface-sunken text-content-secondary',
        className,
      )}
    >
      <Icon aria-hidden className="size-3.5" />
      {change}
    </span>
  );
}
