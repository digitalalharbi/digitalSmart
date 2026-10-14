import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Chart, ChartFrame } from '@/shared/ui/chart';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard, MetricCardSkeleton } from '@/shared/ui/metric-card';
import { SourceNotice } from '@/shared/ui/source-badge';
import { PageHeader } from '@/shared/ui/page-header';
import { useUiStore } from '@/shared/store/ui-store';
import { BreakdownTable } from './BreakdownTable';
import { breakdownOption, efficiencyOption, trendOption } from '@/shared/metrics';
import { FilterBar } from './FilterBar';
import { formatMetric } from '@/shared/metrics';
import { useAnalytics, useMetricCatalogue } from './useAnalytics';
import type { ComparisonMode, DatePreset, DimensionValue } from '@/shared/metrics';

/** The scorecard an agency looks at first, in the order it reads them. */
const SCORECARD = [
  'spend',
  'revenue',
  'roas',
  'conversions',
  'cpa',
  'impressions',
  'clicks',
  'ctr',
  'cpc',
  'cpm',
] as const;

const BREAKDOWN_METRICS = [
  'spend',
  'impressions',
  'clicks',
  'ctr',
  'cpc',
  'conversions',
  'cpa',
  'roas',
];
const GROUP_BY: readonly DimensionValue[] = ['provider', 'campaign', 'ad_group', 'ad'];

export function AnalyticsPage() {
  const { t, i18n } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const isRtl = locale === 'ar';

  const [preset, setPreset] = useState<DatePreset>('28d');
  const [comparison, setComparison] = useState<ComparisonMode>('previous_period');
  const [dimension, setDimension] = useState<DimensionValue>('campaign');
  const [providers, setProviders] = useState<string[]>([]);
  // Which metric the trend chart plots. Clicking a scorecard tile changes it,
  // which is how a reader investigates the figure that caught their eye.
  const [trendMetric, setTrendMetric] = useState<string>('spend');

  const catalogue = useMetricCatalogue();

  const query = useMemo(
    () => ({
      preset,
      compare: comparison,
      dimension,
      metrics: [...new Set([...SCORECARD, ...BREAKDOWN_METRICS, trendMetric])],
      order_by: 'spend',
      limit: 25,
      ...(providers.length > 0 ? { providers } : {}),
    }),
    [preset, comparison, dimension, providers, trendMetric],
  );

  const { data, isPending, isError, error, isFetching } = useAnalytics(query);

  const chartContext = useMemo(() => ({ locale: i18n.language, isRtl }), [i18n.language, isRtl]);

  const trend = useMemo(
    () =>
      data === undefined
        ? null
        : trendOption(
            data,
            [trendMetric, trendMetric === 'spend' ? 'roas' : 'spend'],
            catalogue.definitions,
            chartContext,
          ),
    [data, trendMetric, catalogue.definitions, chartContext],
  );

  const breakdownChart = useMemo(
    () =>
      data === undefined || data.breakdown.length === 0
        ? null
        : breakdownOption(
            data.breakdown.slice(0, 10),
            'spend',
            catalogue.definitions,
            chartContext,
          ),
    [data, catalogue.definitions, chartContext],
  );

  const efficiency = useMemo(
    () =>
      data === undefined || data.breakdown.length === 0
        ? null
        : efficiencyOption(data.breakdown, 'spend', 'cpa', catalogue.definitions, chartContext),
    [data, catalogue.definitions, chartContext],
  );

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeading />
        <Alert tone="warning">{t('analytics.forbidden')}</Alert>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <PageHeading />

      {/*
        The source notice sits above the numbers on purpose: a reader should meet
        the caveat before drawing a conclusion, not after.
      */}
      {data !== undefined && <SourceNotice source={data.source} />}

      <FilterBar
        preset={preset}
        onPresetChange={setPreset}
        comparison={comparison}
        onComparisonChange={setComparison}
        dimension={dimension}
        onDimensionChange={setDimension}
        dimensions={GROUP_BY}
        providers={catalogue.data?.providers}
        selectedProviders={providers}
        onProviderToggle={(provider) =>
          setProviders((current) =>
            current.includes(provider)
              ? current.filter((value) => value !== provider)
              : [...current, provider],
          )
        }
        isRefreshing={isFetching && !isPending}
      />

      {isError && !(error instanceof ApiError && error.status === 403) && (
        <Alert tone="danger">{t('analytics.loadFailed')}</Alert>
      )}

      {/* Scorecard */}
      <section
        aria-label={t('analytics.scorecard')}
        className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5"
      >
        {isPending
          ? SCORECARD.map((metric) => <MetricCardSkeleton key={metric} />)
          : SCORECARD.map((metric, index) => {
              const value = data?.summary.values[metric];

              if (value === undefined) {
                return null;
              }

              return (
                <MetricCard
                  key={metric}
                  value={value}
                  emphasis={index < 3 ? 'primary' : 'secondary'}
                  onSelect={() => setTrendMetric(metric)}
                  isSelected={trendMetric === metric}
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
            The trend, in a frame that carries a table of the same figures.

            The two charts below it do not need their own twin: the breakdown table at the
            bottom of the page holds every one of their values, by the same dimension. The
            trend's daily figures appear nowhere else.
          */}
          {trend === null ? (
            <div className="bg-surface-sunken h-[400px] animate-pulse rounded-lg" />
          ) : (
            <ChartFrame
              title={t('analytics.trend.title', {
                metric: catalogue.definitions.get(trendMetric)?.label ?? trendMetric,
              })}
              subtitle={t('analytics.trend.hint')}
              {...(data === undefined ? {} : { source: data.source })}
              table={{
                columns: [
                  t('analytics.table.day'),
                  catalogue.definitions.get(trendMetric)?.label ?? trendMetric,
                  catalogue.definitions.get('spend')?.label ?? 'spend',
                ],
                rows: (data?.series ?? []).map((point) => {
                  const chosen = point.values[trendMetric];
                  const spend = point.values.spend;

                  return {
                    key: point.date,
                    label: point.date,
                    cells: [
                      // A missing figure is a dash with its reason, never a zero.
                      chosen === undefined ? '—' : formatMetric(chosen, i18n.language),
                      spend === undefined ? '—' : formatMetric(spend, i18n.language),
                    ],
                  };
                }),
              }}
            >
              <Chart
                option={trend}
                /*
                 * Taller than a single plot, because this is two: the chosen metric above,
                 * spend beneath it, sharing one time axis. Sized to include both x-axis
                 * bands — a container that fits only the plots gives the card a tiny
                 * nested scrollbar.
                 */
                height={400}
                ariaLabel={t('analytics.trend.aria', {
                  metric: catalogue.definitions.get(trendMetric)?.label ?? trendMetric,
                  start: data?.range.start ?? '',
                  end: data?.range.end ?? '',
                })}
              >
                {/* The same figures in text, for anyone who cannot see the canvas. */}
                <ul>
                  {(data?.series ?? []).map((point) => {
                    const value = point.values[trendMetric];

                    return (
                      <li key={point.date}>
                        {point.date}:{' '}
                        {value === undefined ? '—' : formatMetric(value, i18n.language)}
                      </li>
                    );
                  })}
                </ul>
              </Chart>
            </ChartFrame>
          )}

          {/* Mix and efficiency, side by side on a wide canvas */}
          <div className="grid gap-5 xl:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>
                  {t('analytics.mix.title', { dimension: t(`analytics.dimensions.${dimension}`) })}
                </CardTitle>
              </CardHeader>
              <CardContent>
                {breakdownChart === null ? (
                  <EmptyState title={t('analytics.empty.breakdown')} />
                ) : (
                  <Chart
                    option={breakdownChart}
                    height={300}
                    ariaLabel={t('analytics.mix.aria', {
                      dimension: t(`analytics.dimensions.${dimension}`),
                    })}
                  />
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('analytics.efficiency.title')}</CardTitle>
                {/* Says what the chart is for, because a scatter is not self-explanatory. */}
                <p className="text-content-secondary text-sm">
                  {t('analytics.efficiency.question')}
                </p>
              </CardHeader>
              <CardContent>
                {efficiency === null ? (
                  <EmptyState title={t('analytics.empty.breakdown')} />
                ) : (
                  <Chart
                    option={efficiency}
                    height={300}
                    ariaLabel={t('analytics.efficiency.aria')}
                  />
                )}
              </CardContent>
            </Card>
          </div>

          {/* The table, which is where the detail actually lives */}
          <section className="flex flex-col gap-3" aria-label={t('analytics.table.title')}>
            <h2 className="text-content text-[0.9375rem] font-semibold">
              {t('analytics.table.title', { dimension: t(`analytics.dimensions.${dimension}`) })}
            </h2>
            {data === undefined || data.breakdown.length === 0 ? (
              <EmptyState title={t('analytics.empty.breakdown')} />
            ) : (
              <BreakdownTable
                rows={data.breakdown}
                metrics={BREAKDOWN_METRICS}
                definitions={catalogue.definitions}
                dimensionLabel={t(`analytics.dimensions.${dimension}`)}
              />
            )}
          </section>
        </>
      )}
    </div>
  );
}

function PageHeading() {
  const { t } = useTranslation();

  return <PageHeader title={t('nav.analytics')} description={t('analytics.subtitle')} />;
}
