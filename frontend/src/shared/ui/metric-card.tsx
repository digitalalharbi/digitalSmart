import { ArrowDownRight, ArrowUpRight, Info, Minus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatChange, formatMetric, MISSING } from '@/shared/metrics';
import type { MetricValue } from '@/shared/metrics';
import { cn } from '@/shared/lib/cn';
import { SourceBadge } from '@/shared/ui/source-badge';

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
        'bg-surface border-line-subtle flex flex-col gap-1.5 rounded-xl border px-4 py-3.5 text-start',
        'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
        isInteractive &&
          'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
        isSelected && 'border-accent ring-accent/25 ring-1',
        className,
      )}
    >
      <div className="flex items-center gap-1.5">
        <span className="text-content-secondary text-xs font-medium tracking-wide uppercase">
          {value.label}
        </span>
        {value.unavailable_reason !== null && (
          <span
            className="text-content-tertiary inline-flex"
            title={value.unavailable_reason}
            aria-label={value.unavailable_reason}
          >
            <Info aria-hidden className="size-3.5" />
          </span>
        )}
      </div>

      <span
        className={cn(
          'ds-numeric text-content font-semibold',
          emphasis === 'primary' ? 'text-2xl' : 'text-xl',
          value.value === null && 'text-content-tertiary font-normal',
        )}
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
          {value.unavailable_reason}
        </span>
      ) : (
        <div className="flex items-center gap-2 text-xs">
          {change !== null ? (
            <span
              className={cn(
                'ds-numeric inline-flex items-center gap-0.5 font-medium',
                value.change_is_good === true && 'text-success',
                value.change_is_good === false && 'text-danger',
                value.change_is_good === null && 'text-content-tertiary',
              )}
            >
              {value.change_ratio !== null && value.change_ratio > 0 ? (
                <ArrowUpRight aria-hidden className="size-3.5" />
              ) : value.change_ratio !== null && value.change_ratio < 0 ? (
                <ArrowDownRight aria-hidden className="size-3.5" />
              ) : (
                <Minus aria-hidden className="size-3.5" />
              )}
              {change}
            </span>
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
    <div className="bg-surface border-line-subtle flex flex-col gap-2 rounded-xl border px-4 py-3.5">
      <div className="bg-surface-sunken h-3 w-20 animate-pulse rounded" />
      <div className="bg-surface-sunken h-7 w-28 animate-pulse rounded" />
      <div className="bg-surface-sunken h-3 w-16 animate-pulse rounded" />
    </div>
  );
}

export { MISSING };
