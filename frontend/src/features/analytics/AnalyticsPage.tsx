import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Choice } from '@/shared/ui/choice';
import { Chart, ChartFrame } from '@/shared/ui/chart';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard, MetricCardSkeleton } from '@/shared/ui/metric-card';
import { ReportHighlights } from '@/features/reports';
import { SourceNotice } from '@/shared/ui/source-badge';
import { PageHeader } from '@/shared/ui/page-header';
import { useProvidersQuery } from '@/features/integrations';
import { useUiStore } from '@/shared/store/ui-store';
import { analyticsAsReport } from './asReport';
import { AttributionPanel } from './AttributionPanel';
import { BreakdownTable } from './BreakdownTable';
import { PacingStrip } from './PacingStrip';
import { HERO_METRICS as HERO } from './heroMetrics';
import { PlatformPanel } from './PlatformPanel';
import { efficiencyOption, trendOption } from '@/shared/metrics';
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

  /*
   * The shared period: 7, 30 or 90 days and its comparison come from — and go back to — the one choice every
   * analytical page reads. A preset only this page offers (this month, last month, 14 or 28 days) stays local.
   */
  const period = useUiStore((state) => state.period);
  const setPeriod = useUiStore((state) => state.setPeriod);
  const [localPreset, setLocalPreset] = useState<DatePreset | null>(null);
  const preset: DatePreset =
    localPreset ?? ({ 7: '7d', 30: '30d', 90: '90d' } as const)[period.days];
  const comparison: ComparisonMode = period.compare;
  const setPreset = (next: DatePreset) => {
    const shared = ({ '7d': 7, '30d': 30, '90d': 90 } as const)[next as '7d' | '30d' | '90d'];

    if (shared === undefined) {
      setLocalPreset(next);
    } else {
      setLocalPreset(null);
      setPeriod({ ...period, days: shared });
    }
  };
  const setComparison = (next: ComparisonMode) => setPeriod({ ...period, compare: next });
  const [dimension, setDimension] = useState<DimensionValue>('campaign');
  const [providers, setProviders] = useState<string[]>([]);
  // Which metric the trend chart plots. Clicking a scorecard tile changes it,
  // which is how a reader investigates the figure that caught their eye.
  const [trendMetric, setTrendMetric] = useState<string>('spend');

  /*
   * The working context narrows this surface like every other; the topbar chip says so on every screen. One client,
   * one project, or everything.
   */
  const context = useUiStore((state) => state.context);
  const clientId = context.client?.id ?? null;
  const projectId = context.project?.id ?? null;

  const catalogue = useMetricCatalogue();
  const registry = useProvidersQuery();

  const query = useMemo(
    () => ({
      preset,
      compare: comparison,
      dimension,
      metrics: [...new Set([...SCORECARD, ...BREAKDOWN_METRICS, trendMetric])],
      order_by: 'spend',
      limit: 25,
      ...(providers.length > 0 ? { providers } : {}),
      ...(clientId === null ? {} : { client_id: clientId }),
      ...(projectId === null ? {} : { project_ids: [projectId] }),
    }),
    [preset, comparison, dimension, providers, trendMetric, clientId, projectId],
  );

  const { data, isPending, isError, error, isFetching } = useAnalytics(query);

  /*
   * The platforms and the campaigns, each as its own ranking, over the same period, comparison and context. The
   * platform panel deliberately ignores the platform filter: it is where the filter is chosen, and a panel that
   * collapsed to the one platform it had just selected could not be used to choose another.
   */
  const rankingQuery = (by: DimensionValue, limit: number) => ({
    preset,
    compare: comparison,
    dimension: by,
    metrics: BREAKDOWN_METRICS,
    order_by: 'spend',
    limit,
    ...(clientId === null ? {} : { client_id: clientId }),
    ...(projectId === null ? {} : { project_ids: [projectId] }),
  });
  const platforms = useAnalytics(rankingQuery('provider', 12));
  const campaignRows = useAnalytics({
    ...rankingQuery('campaign', 25),
    ...(providers.length > 0 ? { providers } : {}),
  });

  /* The funnel, the insights, the campaign cards and the pointers are the report system's own, over these figures. */
  const asReport = useMemo(
    () => (data === undefined ? null : analyticsAsReport(data, platforms.data, campaignRows.data)),
    [data, platforms.data, campaignRows.data],
  );
  const [showDetails, setShowDetails] = useState(false);

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

  const efficiency = useMemo(
    () =>
      data === undefined || data.breakdown.length === 0
        ? null
        : efficiencyOption(data.breakdown, 'spend', 'cpa', catalogue.definitions, chartContext),
    [data, catalogue.definitions, chartContext],
  );

  /*
   * Only platforms that report campaign figures. A store, an analytics property and a CRM are in the registry too,
   * and a filter chip for each would offer a choice that can only ever narrow the page to nothing.
   */
  const advertising = new Set(
    (registry.data ?? [])
      .filter((row) => row.category === 'advertising')
      .map((row) => row.provider),
  );
  const advertisingProviders =
    registry.data === undefined
      ? catalogue.data?.providers
      : catalogue.data?.providers.filter((provider) => advertising.has(provider.value as never));

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
        providers={advertisingProviders}
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
                  /*
                   * How the figure moved, from its own daily values — the series the hero chart draws, so the two can
                   * never disagree. A day with no figure is a break in the line, not a zero.
                   */
                  trend={(data?.series ?? []).map((point) => point.values[metric]?.value ?? null)}
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
          <div
            className="flex flex-wrap gap-2"
            role="group"
            aria-label={t('analytics.hero.metric')}
          >
            {HERO.map((metric) => (
              <Choice
                key={metric}
                isSelected={trendMetric === metric}
                onSelect={() => setTrendMetric(metric)}
              >
                {catalogue.definitions.get(metric)?.label ?? metric}
              </Choice>
            ))}
          </div>

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

          {/* Platforms: where the money went, and each platform's own figures. A card narrows the page. */}
          {platforms.data === undefined ? null : (
            <PlatformPanel
              data={platforms.data}
              selected={providers}
              onToggle={(provider) =>
                setProviders((current) =>
                  current.includes(provider)
                    ? current.filter((value) => value !== provider)
                    : [...current, provider],
                )
              }
            />
          )}

          {/*
            What the figures say, and the funnel, and the campaigns that carried them: the report system's own cards
            over this page's own figures, so a client's live report and this page cannot disagree about the same week.
          */}
          {asReport === null ? null : (
            <ReportHighlights report={asReport} onViewDetails={() => setShowDetails(true)} />
          )}

          <PacingStrip clientId={clientId} projectId={projectId} locale={i18n.language} />

          {data === undefined ? null : <AttributionPanel data={data} clientId={clientId} />}

          {/* The working: efficiency against scale, and every row. Detail, not the answer, so it opens on request. */}
          <section aria-label={t('analytics.table.title')} className="flex flex-col gap-3">
            <button
              type="button"
              aria-expanded={showDetails}
              onClick={() => setShowDetails((open) => !open)}
              className="text-content flex w-fit items-center gap-2 text-[0.9375rem] font-semibold"
            >
              {t('analytics.details', { dimension: t(`analytics.dimensions.${dimension}`) })}
            </button>
            {!showDetails ? null : (
              <>
                <Card>
                  <CardHeader>
                    <CardTitle>{t('analytics.efficiency.title')}</CardTitle>
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
              </>
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
