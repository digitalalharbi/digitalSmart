import { Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatChange, formatMetric, MISSING } from '@/shared/metrics';
import type { MetricValue } from '@/shared/metrics';
import { cn } from '@/shared/lib/cn';
import { SourceBadge } from '@/shared/ui/source-badge';
import { TrendBadge } from '@/shared/ui/trend-badge';

type MetricCardProps = {
  value: MetricValue;
  /** Emphasis for the two or three figures a reader should see first. */
  emphasis?: 'primary' | 'secondary';
  className?: string;
  onSelect?: () => void;
  isSelected?: boolean;
};

/**
 * One figure on a scorecard.
 *
 * Three decisions matter more than the layout:
 *
 *  - A missing figure shows an em dash **and its reason**, because "not reported by
 *    this platform" and "reach cannot be added across 4 campaigns" are different
 *    facts and both are actionable. A dash alone just looks broken.
 *  - A change is coloured by whether it is *good*, which the server decides from
 *    the metric's direction: CPA falling 20% is green, spend falling 20% is not.
 *  - Numbers use tabular figures so a column of them aligns on the decimal, which
 *    is what makes a scorecard scannable rather than merely dense.
 */
export function MetricCard({
  value,
  emphasis = 'secondary',
  className,
  onSelect,
  isSelected = false,
}: MetricCardProps) {
  const { t, i18n } = useTranslation();
  const change = formatChange(value, i18n.language);
  const isInteractive = onSelect !== undefined;

  const Element = isInteractive ? 'button' : 'div';

  return (
    <Element
      {...(isInteractive
        ? { type: 'button' as const, onClick: onSelect, 'aria-pressed': isSelected }
        : {})}
      className={cn(
        /*
         * Generous padding and a large radius: a scorecard is the first thing read on every analytical page,
         * and the references' confidence comes from giving the figure room rather than from decoration.
         */
        'bg-surface flex flex-col gap-2 rounded-3xl p-5 text-start shadow-xs',
        'ease-standard transition-[border-color,box-shadow,transform] duration-[var(--ds-duration-fast)]',
        isInteractive &&
          'hover:border-line focus-visible:ring-accent/40 hover:shadow-sm focus-visible:ring-2 focus-visible:outline-none',
        isSelected && 'border-accent ring-accent/25 ring-1',
        className,
      )}
    >
      <div className="flex items-center gap-1.5">
        <span className="text-content-tertiary ds-overline">{value.label}</span>
        {value.unavailable_reason !== null && (
          <span
            role="img"
            className="text-content-tertiary inline-flex"
            title={value.unavailable_reason}
            aria-label={value.unavailable_reason}
          >
            <Info aria-hidden className="size-3.5" />
          </span>
        )}
      </div>

      {/*
        The figure dominates. A KPI card whose value is the same size as its label is a card nobody can scan,
        and scanning is the entire job of a scorecard row.
      */}
      <span
        className={cn(
          /* Steps down on a phone and truncates rather than overflow: a nine-digit figure in a half-width card. */
          'ds-figure text-content max-w-full min-w-0 truncate',
          emphasis === 'primary' ? 'text-2xl sm:text-3xl' : 'text-xl sm:text-2xl',
          value.value === null && 'text-content-tertiary text-xl font-normal sm:text-2xl',
        )}
        title={formatMetric(value, i18n.language)}
      >
        {formatMetric(value, i18n.language)}
      </span>

      {value.value === null && value.unavailable_reason !== null ? (
        /*
         * The reason, in place of the comparison. A reader who sees a dash and no
         * explanation assumes the product is broken; one who sees "not reported by
         * this platform" knows it is working and the platform is the limit.
         */
        <span className="text-content-tertiary text-xs leading-snug">
          <span aria-hidden>{t('analytics.notAvailable')}</span>
          <span className="sr-only">{value.unavailable_reason}</span>
        </span>
      ) : (
        <div className="flex flex-wrap items-center gap-2 text-xs">
          {change !== null ? (
            <TrendBadge change={change} ratio={value.change_ratio} isGood={value.change_is_good} />
          ) : (
            <span className="text-content-tertiary">{t('analytics.noComparison')}</span>
          )}
          <SourceBadge source={value.source} />
        </div>
      )}
    </Element>
  );
}

/** A scorecard placeholder that holds its own shape while loading. */
export function MetricCardSkeleton() {
  return (
    <div className="bg-surface border-line-subtle flex flex-col gap-2.5 rounded-2xl border p-5 shadow-xs">
      <div className="bg-surface-sunken h-3 w-20 animate-pulse rounded-full" />
      <div className="bg-surface-sunken h-8 w-32 animate-pulse rounded-lg" />
      <div className="bg-surface-sunken h-5 w-20 animate-pulse rounded-full" />
    </div>
  );
}

export { MISSING };
