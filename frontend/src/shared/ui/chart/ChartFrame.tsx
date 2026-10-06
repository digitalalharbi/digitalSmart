import { Table2, TrendingUp } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { MetricSourceValue } from '@/shared/metrics';
import { cn } from '@/shared/lib/cn';
import { EmptyState } from '@/shared/ui/empty-state';
import { SourceBadge } from '@/shared/ui/source-badge';
import { ChartTable, type ChartTableRow } from './ChartTable';

type ChartFrameProps = {
  title: string;
  /** What question the chart answers. Charts that answer none do not belong on a page. */
  subtitle?: string;
  /**
   * Where the figures came from.
   *
   * Not optional by accident: a chart drawn from sandbox fixtures and a chart drawn from
   * a client's live spend look identical, and only one of them may be acted on.
   */
  source?: MetricSourceValue;
  /**
   * What this chart cannot say.
   *
   * Rendered as data rather than left to documentation, because the person reading the
   * page is the one who would otherwise draw the wrong conclusion from it.
   */
  caveats?: string[];
  /** The same figures as a table. Every chart has one — see `ChartTable`. */
  table: { columns: string[]; rows: ChartTableRow[] };
  /** True when there is nothing to draw, which is different from drawing zeroes. */
  isEmpty?: boolean;
  emptyTitle?: string;
  emptyBody?: string;
  /** True while newer figures are on their way. */
  isRefreshing?: boolean;
  className?: string;
  children: ReactNode;
};

/**
 * The frame every chart in this product sits in.
 *
 * It exists because the honest parts of a chart are the parts people leave out. A title
 * says what is plotted; a source badge says whether it may be believed; a caveat says
 * what the chart is silent about; and a table makes every value reachable without colour
 * or a pointer. Putting them in one component is what stops the ninth chart from being
 * the one that forgot.
 *
 * Two behaviours are deliberate:
 *
 *  - **Empty is a sentence, not an empty grid.** Axes with no marks read as zero, and
 *    zero and "nothing recorded" mean opposite things to anyone deciding a budget.
 *  - **A refresh dims the previous render instead of flashing a skeleton.** The figures
 *    on screen are still the best answer available, and a layout that jumps every
 *    minute is a layout nobody can read.
 */
export function ChartFrame({
  title,
  subtitle,
  source,
  caveats,
  table,
  isEmpty = false,
  emptyTitle,
  emptyBody,
  isRefreshing = false,
  className,
  children,
}: ChartFrameProps) {
  const { t } = useTranslation();
  const [view, setView] = useState<'chart' | 'table'>('chart');
  const panelId = useId();
  const titleId = useId();

  return (
    <section
      aria-labelledby={titleId}
      className={cn('bg-surface border-line-subtle rounded-xl border shadow-xs', className)}
    >
      <header className="flex flex-wrap items-start justify-between gap-3 px-5 pt-5 pb-3">
        <div className="flex min-w-0 flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 id={titleId} className="text-content text-[0.9375rem] leading-6 font-semibold">
              {title}
            </h3>
            {source === undefined ? null : <SourceBadge source={source} />}
          </div>
          {subtitle === undefined ? null : (
            <p className="text-content-secondary text-sm leading-relaxed">{subtitle}</p>
          )}
        </div>

        {/*
          The table is a peer of the chart, not a hidden accessibility concession, so the
          switch is in the open where anybody can use it. Plenty of readers prefer it.
        */}
        <div
          role="group"
          aria-label={t('chart.view.label')}
          className="border-line flex shrink-0 overflow-hidden rounded-md border"
        >
          {(
            [
              ['chart', TrendingUp, t('chart.view.chart')],
              ['table', Table2, t('chart.view.table')],
            ] as const
          ).map(([value, Icon, label]) => (
            <button
              key={value}
              type="button"
              onClick={() => setView(value)}
              aria-pressed={view === value}
              aria-controls={panelId}
              className={cn(
                'focus-visible:ring-accent/40 flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium',
                'ease-standard transition-colors duration-[var(--ds-duration-fast)] focus-visible:ring-2 focus-visible:outline-none',
                view === value
                  ? 'bg-accent-subtle text-content-brand'
                  : 'text-content-secondary hover:bg-surface-hover',
              )}
            >
              <Icon aria-hidden className="size-3.5" />
              {label}
            </button>
          ))}
        </div>
      </header>

      <div
        id={panelId}
        className={cn(
          'px-5 pb-5',
          // A dim, not a skeleton: the figures on screen are still the best answer there is.
          isRefreshing &&
            'ease-standard opacity-60 transition-opacity duration-[var(--ds-duration-base)]',
        )}
      >
        {isEmpty ? (
          <EmptyState
            title={emptyTitle ?? t('chart.empty.title')}
            description={emptyBody ?? t('chart.empty.body')}
          />
        ) : view === 'chart' ? (
          children
        ) : (
          <ChartTable columns={table.columns} rows={table.rows} caption={title} />
        )}
      </div>

      {caveats === undefined || caveats.length === 0 ? null : (
        <footer className="border-line-subtle border-t px-5 py-3">
          <ul className="text-content-tertiary flex flex-col gap-1 text-xs leading-relaxed">
            {caveats.map((caveat) => (
              <li key={caveat}>{caveat}</li>
            ))}
          </ul>
        </footer>
      )}
    </section>
  );
}
