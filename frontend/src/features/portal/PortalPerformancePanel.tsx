import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatChange, formatMetric, MISSING } from '@/shared/metrics';
import type { MetricSourceValue } from '@/shared/metrics';
import {
  baseOption,
  Chart,
  ChartFrame,
  legend,
  lineSeries,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';
import { MetricCard } from '@/shared/ui/metric-card';
import { Skeleton } from '@/shared/ui/skeleton';
import { EmptyState } from '@/shared/ui/empty-state';
import { Alert } from '@/shared/ui/alert';
import { usePortalPerformanceQuery } from './usePortal';

/** The three the trend draws. Spend buys clicks, clicks become conversions; the rest are ratios of these. */
const TRENDED = ['spend', 'clicks', 'conversions'] as const;

/**
 * What the advertising did, as the client reads it.
 *
 * Two sentences on this panel are the whole point of it. The **comparison period is named**, because a change
 * figure whose baseline is unstated is one somebody reads against whatever period they had in mind. And **the
 * period is said to end yesterday**, because a client who compares this with a platform dashboard showing today
 * will otherwise conclude the agency's figures are wrong.
 */
export function PortalPerformancePanel({ clientId }: { clientId: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const performance = usePortalPerformanceQuery(clientId);
  const data = performance.data;

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const series = data?.series ?? [];

    return {
      ...base,
      grid: { top: 30, bottom: 8, left: 8, right: 8, containLabel: true },
      legend: legend({ locale, isRtl }, palette, TRENDED.length),
      tooltip: { ...(base.tooltip as object), trigger: 'axis' },
      xAxis: {
        type: 'category',
        inverse: isRtl,
        data: series.map((point) => point.date),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.axisLabel, fontSize: 11 },
      },
      yAxis: {
        type: 'value',
        position: isRtl ? 'right' : 'left',
        splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.axisLabel, fontSize: 11 },
      },
      series: TRENDED.map((metric, index) => ({
        ...lineSeries(palette, {
          name: series[0]?.values[metric]?.label ?? metric,
          /* Null stays null: a day with no delivery is a gap, not a zero drawn through. */
          data: series.map((point) => point.values[metric]?.value ?? null),
          colour: seriesColour(palette, index),
        }),
      })),
    };
  }, [data, locale, isRtl]);

  const table = useMemo(() => {
    const series = data?.series ?? [];

    return {
      columns: [
        t('reports.table.date'),
        ...TRENDED.map((metric) => series[0]?.values[metric]?.label ?? metric),
      ],
      rows: series.map((point) => ({
        key: point.date,
        label: point.date,
        cells: TRENDED.map((metric) => {
          const value = point.values[metric];

          return value === undefined ? MISSING : formatMetric(value, locale);
        }),
      })),
    };
  }, [data, locale, t]);

  if (performance.isPending) {
    return <Skeleton className="h-96 w-full" />;
  }

  if (data === undefined) {
    return <Alert tone="danger">{t('myPortal.loadFailed')}</Alert>;
  }

  const figures = Object.values(data.totals);

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-col gap-1">
        <p className="text-content text-sm font-medium">
          {t('myPortal.performance.period', {
            from: data.period.from,
            until: data.period.until,
            days: data.period.days,
          })}
        </p>
        {/*
          Named, not implied. A client who finds a percentage and no baseline will supply one of their own.
        */}
        <p className="text-content-secondary text-sm">
          {t('myPortal.performance.comparedWith', {
            from: data.comparison_period.from,
            until: data.comparison_period.until,
          })}
        </p>
        <p className="text-content-tertiary text-xs">{t('myPortal.performance.endsYesterday')}</p>
      </header>

      {data.is_empty ? (
        <EmptyState
          title={t('myPortal.performance.emptyTitle')}
          description={t('myPortal.performance.emptyBody')}
        />
      ) : (
        <>
          <ChartFrame
            title={t('myPortal.performance.headline')}
            source={data.source as MetricSourceValue}
            table={{
              columns: [
                t('reports.table.metric'),
                t('reports.table.value'),
                t('reports.table.change'),
              ],
              rows: figures.map((value) => ({
                key: value.metric,
                label: value.label,
                cells: [
                  formatMetric(value, locale),
                  /* A dash where there is no change to report: no comparison and no change are different facts. */
                  formatChange(value, locale) ?? MISSING,
                ],
              })),
            }}
          >
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {figures.map((value) => (
                <MetricCard key={value.metric} value={value} />
              ))}
            </div>
          </ChartFrame>

          <ChartFrame
            title={t('myPortal.performance.trend')}
            source={data.source as MetricSourceValue}
            table={table}
            isEmpty={table.rows.length === 0}
            emptyTitle={t('myPortal.performance.emptyTitle')}
            emptyBody={t('myPortal.performance.emptyBody')}
          >
            <Chart option={option} height={260} ariaLabel={t('myPortal.performance.trend')}>
              <ul>
                {table.rows.map((row) => (
                  <li key={row.key}>
                    {row.label}: {row.cells.join(', ')}
                  </li>
                ))}
              </ul>
            </Chart>
          </ChartFrame>
        </>
      )}

      <p className="text-content-tertiary text-xs">{t('myPortal.performance.fixed')}</p>
    </div>
  );
}
