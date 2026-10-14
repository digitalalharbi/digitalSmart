import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronRight, Home } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Chart, ChartFrame } from '@/shared/ui/chart';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard, MetricCardSkeleton } from '@/shared/ui/metric-card';
import { SourceNotice } from '@/shared/ui/source-badge';
import { PageHeader } from '@/shared/ui/page-header';
import { useUiStore } from '@/shared/store/ui-store';
import { BreakdownTable, FilterBar, useAnalytics, useMetricCatalogue } from '@/features/analytics';
import {
  formatMetric,
  trendMetricsFor,
  trendOption,
  type BreakdownRowPayload,
  type ComparisonMode,
  type DatePreset,
  type DimensionValue,
} from '@/shared/metrics';
import { cn } from '@/shared/lib/cn';

const SCORECARD = ['spend', 'roas', 'conversions', 'cpa', 'ctr'] as const;
const TABLE_METRICS = [
  'spend',
  'impressions',
  'clicks',
  'ctr',
  'cpc',
  'conversions',
  'cpa',
  'roas',
];

/** One step of the drill-down: platform → campaign → ad group → ad. */
type Crumb = {
  dimension: DimensionValue;
  key: string;
  label: string;
};

/** The dimension one level below the current one. */
const NEXT: Partial<Record<DimensionValue, DimensionValue>> = {
  provider: 'campaign',
  campaign: 'ad_group',
  ad_group: 'ad',
};

/**
 * The campaign explorer.
 *
 * Built around drilling down, because that is the question an operator actually
 * has: not "what is our CTR" but "which ad inside which ad group inside which
 * campaign is dragging it down". Each step filters the next, so the scorecard and
 * the chart narrow with the table rather than staying at workspace level — a
 * breadcrumb that does not change the figures above it is decoration.
 */
export function CampaignsPage() {
  const { t, i18n } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const isRtl = locale === 'ar';

  const [preset, setPreset] = useState<DatePreset>('28d');
  const [comparison, setComparison] = useState<ComparisonMode>('previous_period');
  const [trail, setTrail] = useState<Crumb[]>([]);

  // The level currently listed, derived from how far down the reader has gone.
  const dimension: DimensionValue = useMemo(() => {
    const last = trail.at(-1);

    return last === undefined ? 'provider' : (NEXT[last.dimension] ?? 'ad');
  }, [trail]);

  /*
   * Each crumb contributes its own filter, so the whole surface narrows together.
   * Drilling from a campaign into its ad groups filters by campaign id, and the
   * scorecard above shows that campaign's figures rather than the workspace's.
   */
  const filters = useMemo(() => {
    const result: Record<string, string[]> = {};

    for (const crumb of trail) {
      const key = {
        provider: 'providers',
        campaign: 'campaign_ids',
        ad_group: 'ad_group_ids',
        ad: 'ad_ids',
        external_account: 'account_ids',
        date: 'date',
      }[crumb.dimension];

      if (key !== 'date') {
        result[key] = [...(result[key] ?? []), crumb.key];
      }
    }

    return result;
  }, [trail]);

  const catalogue = useMetricCatalogue();

  const query = useMemo(
    () => ({
      preset,
      compare: comparison,
      dimension,
      metrics: [...new Set([...SCORECARD, ...TABLE_METRICS])],
      order_by: 'spend',
      limit: 50,
      ...filters,
    }),
    [preset, comparison, dimension, filters],
  );

  const { data, isPending, isError, error, isFetching } = useAnalytics(query);

  const chartContext = useMemo(() => ({ locale: i18n.language, isRtl }), [i18n.language, isRtl]);
  const trend = useMemo(
    () =>
      data === undefined
        ? null
        : trendOption(data, trendMetricsFor('spend'), catalogue.definitions, chartContext),
    [data, catalogue.definitions, chartContext],
  );

  const drillInto = (row: BreakdownRowPayload) => {
    if (NEXT[dimension] === undefined) {
      // Already at the deepest level: an ad has nothing below it.
      return;
    }

    setTrail((current) => [...current, { dimension, key: row.key, label: row.label }]);
  };

  const truncateTo = (index: number) => setTrail((current) => current.slice(0, index));

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-content text-2xl font-semibold">{t('nav.campaigns')}</h1>
        <Alert tone="warning">{t('analytics.forbidden')}</Alert>
      </div>
    );
  }

  return (
    <div className="ds-page">
      {/* The breadcrumb is the campaign explorer's whole navigation, so it sits in the header's filter row. */}
      <PageHeader
        title={t('nav.campaigns')}
        filters={<Breadcrumbs trail={trail} onTruncate={truncateTo} />}
      />

      {data !== undefined && <SourceNotice source={data.source} />}

      <FilterBar
        preset={preset}
        onPresetChange={setPreset}
        comparison={comparison}
        onComparisonChange={setComparison}
        providers={trail.length === 0 ? catalogue.data?.providers : undefined}
        isRefreshing={isFetching && !isPending}
      />

      {isError && !(error instanceof ApiError && error.status === 403) && (
        <Alert tone="danger">{t('analytics.loadFailed')}</Alert>
      )}

      <section
        aria-label={t('analytics.scorecard')}
        className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5"
      >
        {isPending
          ? SCORECARD.map((metric) => <MetricCardSkeleton key={metric} />)
          : SCORECARD.map((metric, index) => {
              const value = data?.summary.values[metric];

              return value === undefined ? null : (
                <MetricCard
                  key={metric}
                  value={value}
                  emphasis={index < 2 ? 'primary' : 'secondary'}
                />
              );
            })}
      </section>

      {data?.is_empty === true ? (
        <EmptyState
          title={t('analytics.empty.title')}
          description={t('analytics.empty.description')}
        />
      ) : (
        <>
          {/*
            In the shared frame, like every other chart. Not cosmetic: the frame is what carries the
            source badge, the table twin and the pattern channel for readers colour cannot reach — a
            chart outside it has no way to turn that channel on.
          */}
          <ChartFrame
            title={t('analytics.trend.title', {
              metric: catalogue.definitions.get('spend')?.label ?? 'Spend',
            })}
            subtitle={t('analytics.trend.hint')}
            {...(data === undefined ? {} : { source: data.source })}
            isEmpty={trend === null}
            table={{
              columns: [
                t('analytics.table.day'),
                catalogue.definitions.get('spend')?.label ?? 'Spend',
              ],
              rows: (data?.series ?? []).map((point) => {
                const spend = point.values.spend;

                return {
                  key: point.date,
                  label: point.date,
                  // A missing figure is a dash, never a zero: they mean opposite things.
                  cells: [spend === undefined ? '—' : formatMetric(spend, i18n.language)],
                };
              }),
            }}
          >
            {trend === null ? (
              <div className="bg-surface-sunken h-[260px] animate-pulse rounded-lg" />
            ) : (
              <Chart
                option={trend}
                height={260}
                ariaLabel={t('analytics.trend.aria', {
                  metric: catalogue.definitions.get('spend')?.label ?? 'Spend',
                  start: data?.range.start ?? '',
                  end: data?.range.end ?? '',
                })}
              />
            )}
          </ChartFrame>

          <section className="flex flex-col gap-3">
            <div className="flex items-baseline justify-between gap-3">
              <h2 className="text-content text-[0.9375rem] font-semibold">
                {t('analytics.table.title', { dimension: t(`analytics.dimensions.${dimension}`) })}
              </h2>
              {NEXT[dimension] !== undefined && (
                <span className="text-content-tertiary text-xs">
                  {t('campaigns.drillHint', {
                    level: t(`analytics.dimensions.${NEXT[dimension]}`),
                  })}
                </span>
              )}
            </div>

            {data === undefined || data.breakdown.length === 0 ? (
              <EmptyState title={t('analytics.empty.breakdown')} />
            ) : (
              <BreakdownTable
                rows={data.breakdown}
                metrics={TABLE_METRICS}
                definitions={catalogue.definitions}
                dimensionLabel={t(`analytics.dimensions.${dimension}`)}
                {...(NEXT[dimension] !== undefined ? { onRowSelect: drillInto } : {})}
              />
            )}
          </section>
        </>
      )}
    </div>
  );
}

/**
 * The drill-down trail.
 *
 * A real `<nav>` with an ordered list, and the separator is `ChevronRight` flipped
 * in RTL via `rtl:rotate-180` — a chevron is a direction, and a right-pointing one
 * in an Arabic breadcrumb points back the way the reader came from.
 */
function Breadcrumbs({
  trail,
  onTruncate,
}: {
  trail: Crumb[];
  onTruncate: (index: number) => void;
}) {
  const { t } = useTranslation();

  return (
    <nav aria-label={t('campaigns.breadcrumb')}>
      <ol className="text-content-secondary flex flex-wrap items-center gap-1.5 text-sm">
        <li>
          <button
            type="button"
            onClick={() => onTruncate(0)}
            className={cn(
              'focus-visible:ring-accent/40 inline-flex items-center gap-1.5 rounded px-1 focus-visible:ring-2 focus-visible:outline-none',
              trail.length === 0 ? 'text-content font-medium' : 'hover:text-content',
            )}
            aria-current={trail.length === 0 ? 'page' : undefined}
          >
            <Home aria-hidden className="size-3.5" />
            {t('campaigns.allPlatforms')}
          </button>
        </li>
        {trail.map((crumb, index) => {
          const isLast = index === trail.length - 1;

          return (
            <li key={`${crumb.dimension}-${crumb.key}`} className="flex items-center gap-1.5">
              <ChevronRight aria-hidden className="text-content-tertiary size-3.5 rtl:rotate-180" />
              <button
                type="button"
                onClick={() => onTruncate(index + 1)}
                className={cn(
                  'focus-visible:ring-accent/40 max-w-[16rem] truncate rounded px-1 focus-visible:ring-2 focus-visible:outline-none',
                  isLast ? 'text-content font-medium' : 'hover:text-content',
                )}
                aria-current={isLast ? 'page' : undefined}
              >
                {crumb.label}
              </button>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
