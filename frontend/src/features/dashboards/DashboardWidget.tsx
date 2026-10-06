import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useAnalytics, useMetricCatalogue, BreakdownTable } from '@/features/analytics';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Chart } from '@/shared/ui/chart/Chart';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard, MetricCardSkeleton } from '@/shared/ui/metric-card';
import { SourceBadge } from '@/shared/ui/source-badge';
import { useUiStore } from '@/shared/store/ui-store';
import {
  widgetBarOption,
  widgetLineOption,
  widgetScatterOption,
  widgetShareOption,
  type AnalyticsQuery,
} from '@/shared/metrics';
import type { DashboardFilters, WidgetDraft } from './types';

type DashboardWidgetProps = {
  widget: WidgetDraft;
  /** The dashboard's filters, which this widget inherits unless it overrides them. */
  inherited: DashboardFilters;
};

/**
 * One widget, fetching and rendering its own data.
 *
 * Each widget runs its own query rather than the dashboard running one and slicing
 * it. That costs requests and buys the thing that makes a dashboard useful: widgets
 * can ask different questions — different dimensions, different periods, different
 * platforms — and a shared query could only serve the lowest common denominator.
 * TanStack Query dedupes identical ones, so widgets that genuinely match share a
 * fetch anyway.
 */
export function DashboardWidget({ widget, inherited }: DashboardWidgetProps) {
  const { t, i18n } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const isRtl = locale === 'ar';
  const catalogue = useMetricCatalogue();

  /*
   * Overrides replace the inherited filters they name and leave the rest. Null
   * overrides mean "inherit everything", which is why the spread is conditional
   * rather than unconditional — `{...inherited, ...null}` would quietly work and
   * hide the distinction the model is built on.
   */
  const query: AnalyticsQuery = useMemo(() => {
    const filters = { ...inherited, ...(widget.filter_overrides ?? {}) };

    return {
      ...filters,
      metrics: widget.metrics,
      // A scorecard and a trend have no grouping; everything else needs one.
      dimension: widget.type === 'scorecard' || widget.type === 'line' ? null : widget.dimension,
      order_by: widget.metrics[0],
      limit: widget.type === 'share' ? 8 : 15,
    } as AnalyticsQuery;
  }, [inherited, widget]);

  const { data, isPending, isError, error } = useAnalytics(query);

  const chartContext = useMemo(
    () => ({
      locale: i18n.language,
      isRtl,
      // The handful of words a canvas has to carry itself; see widgetCharts' Context.
      labels: {
        other: t('chart.legend.other'),
        indexed: (date: string) => t('chart.axis.indexed', { date }),
      },
    }),
    [i18n.language, isRtl, t],
  );

  const option = useMemo(() => {
    if (data === undefined) {
      return null;
    }

    const [first, second] = widget.metrics;

    switch (widget.type) {
      case 'line':
        return widgetLineOption(
          data,
          widget.metrics.slice(0, 2),
          catalogue.definitions,
          chartContext,
        );
      case 'bar':
        return first === undefined || data.breakdown.length === 0
          ? null
          : widgetBarOption(data.breakdown, first, catalogue.definitions, chartContext);
      case 'share':
        return first === undefined || data.breakdown.length === 0
          ? null
          : widgetShareOption(data.breakdown, first, chartContext);
      case 'scatter':
        return first === undefined || second === undefined || data.breakdown.length === 0
          ? null
          : widgetScatterOption(
              data.breakdown,
              [first, second],
              catalogue.definitions,
              chartContext,
            );
      default:
        return null;
    }
  }, [data, widget, catalogue.definitions, chartContext]);

  if (isError) {
    const isForbidden = error instanceof ApiError && error.status === 403;

    return (
      <Alert tone={isForbidden ? 'warning' : 'danger'}>
        {isForbidden ? t('analytics.forbidden') : t('analytics.loadFailed')}
      </Alert>
    );
  }

  if (isPending) {
    return widget.type === 'scorecard' ? (
      <MetricCardSkeleton />
    ) : (
      <div className="bg-surface-sunken h-full min-h-[8rem] animate-pulse rounded-lg" />
    );
  }

  if (data === undefined || data.is_empty) {
    return <EmptyState title={t('analytics.empty.title')} className="h-full py-8" />;
  }

  if (widget.type === 'scorecard') {
    const metric = widget.metrics[0];
    const value = metric === undefined ? undefined : data.summary.values[metric];

    return value === undefined ? (
      <EmptyState title={t('dashboards.widget.noMetric')} className="h-full py-8" />
    ) : (
      <MetricCard value={value} emphasis="primary" className="h-full border-0 px-0 py-0" />
    );
  }

  if (widget.type === 'table') {
    return data.breakdown.length === 0 ? (
      <EmptyState title={t('analytics.empty.breakdown')} className="h-full py-8" />
    ) : (
      <BreakdownTable
        rows={data.breakdown}
        metrics={widget.metrics}
        definitions={catalogue.definitions}
        dimensionLabel={t(`analytics.dimensions.${widget.dimension ?? 'campaign'}`)}
      />
    );
  }

  if (option === null) {
    return <EmptyState title={t('analytics.empty.breakdown')} className="h-full py-8" />;
  }

  return (
    <div className="flex h-full flex-col gap-2">
      <Chart
        option={option}
        height="100%"
        className="min-h-0 flex-1"
        ariaLabel={t(`dashboards.widget.aria.${widget.type}`, {
          metric: widget.metrics.join(', '),
          dimension: widget.dimension === null ? '' : t(`analytics.dimensions.${widget.dimension}`),
          defaultValue: widget.title ?? widget.type,
        })}
      />
      {/* The source state travels with every widget, not just the page header. */}
      {data.requires_label && (
        <div className="flex justify-end">
          <SourceBadge source={data.source} />
        </div>
      )}
    </div>
  );
}
