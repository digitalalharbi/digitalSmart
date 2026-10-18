import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { ArrowUpRight } from 'lucide-react';
import { cn } from '@/shared/lib/cn';

/**
 * The card every chart lives in: a title, the one figure the chart is about, an optional change against the previous
 * period, the chart, and a compact legend or insight underneath.
 *
 * `min-w-0` is not decoration. A chart card is nearly always a grid item, and grid items refuse to shrink below their
 * content's minimum width — which for a chart is its longest axis label. One long campaign name was enough to widen a
 * row past a phone and scroll the page sideways.
 */
export function ChartCard({
  title,
  value,
  change,
  action,
  to,
  actionLabel,
  children,
  className,
}: {
  title: string;
  /** The headline figure, already formatted: "SAR 13.6M". */
  value?: string | undefined;
  /** The change against the previous period, e.g. +18% — shown beside the figure, never explained. */
  change?: ReactNode;
  action?: ReactNode;
  /** Where "see more" goes. */
  to?: string | undefined;
  actionLabel?: string | undefined;
  children: ReactNode;
  className?: string | undefined;
}) {
  return (
    <section
      className={cn('bg-surface flex min-w-0 flex-col gap-4 rounded-3xl p-5 shadow-xs', className)}
    >
      <header className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-1">
          <h3 className="text-content text-base font-bold tracking-tight">{title}</h3>
          {value === undefined ? null : (
            <p className="flex items-baseline gap-2">
              <span className="ds-numeric text-content text-2xl font-extrabold">{value}</span>
              {change}
            </p>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {action}
          {to === undefined || actionLabel === undefined ? null : (
            <Link
              to={to}
              className="text-content-secondary hover:text-content inline-flex items-center gap-1 text-sm"
            >
              {actionLabel}
              <ArrowUpRight aria-hidden className="size-4 rtl:-scale-x-100" />
            </Link>
          )}
        </div>
      </header>
      {children}
    </section>
  );
}

/** +18%, −7% or stable, as a small pill. Colour says whether the move is good, not whether it is up. */
export function ChangePill({
  ratio,
  goodWhenUp = true,
  stableLabel,
}: {
  ratio: number | null;
  goodWhenUp?: boolean;
  stableLabel: string;
}) {
  if (ratio === null) {
    return null;
  }

  const rounded = Math.round(ratio * 100);

  if (rounded === 0) {
    return (
      <span className="bg-surface-sunken text-content-secondary rounded-full px-2 py-0.5 text-xs font-semibold">
        {stableLabel}
      </span>
    );
  }

  const good = rounded > 0 === goodWhenUp;

  return (
    <span
      className={cn(
        'ds-numeric rounded-full px-2 py-0.5 text-xs font-semibold',
        good ? 'bg-success-surface text-success' : 'bg-danger-surface text-danger',
      )}
    >
      {rounded > 0 ? '+' : '−'}
      {Math.abs(rounded)}%
    </span>
  );
}
