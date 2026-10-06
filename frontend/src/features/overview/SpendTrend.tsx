import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact } from '@/shared/lib/format';
import { formatMetric } from '@/shared/metrics';
import type { AnalyticsPayload, MetricSourceValue } from '@/shared/metrics';
import {
  baseOption,
  categoryAxis,
  Chart,
  ChartFrame,
  lineSeries,
  readChartPalette,
  valueAxis,
} from '@/shared/ui/chart';

type SpendTrendProps = {
  analytics: AnalyticsPayload;
};

/**
 * Twenty-eight days of spend, on the landing screen.
 *
 * The scorecard above it says what was spent; this says **when**, which is a different and
 * usually more useful question. A month's spend concentrated into four days and the same total
 * spread evenly are two different campaigns, and the four figures above cannot tell them apart.
 *
 * One series, so no legend — the card's title says what is plotted, and a box with one swatch in
 * it would only restate it. Days with no data are a gap rather than a zero: a flat segment says
 * delivery continued at some level, and a sync that has not landed says we do not know.
 */
export function SpendTrend({ analytics }: SpendTrendProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const points = useMemo(
    () =>
      analytics.series.map((point) => {
        const value = point.values.spend;

        return {
          date: point.date,
          value:
            value === undefined || value.value === null
              ? null
              : value.is_minor_units
                ? value.value / 100
                : value.value,
          formatted: value === undefined ? '—' : formatMetric(value, locale),
        };
      }),
    [analytics.series, locale],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);

    return {
      ...base,
      grid: {
        top: 16,
        bottom: 20,
        left: isRtl ? 8 : 48,
        right: isRtl ? 48 : 8,
        containLabel: true,
      },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'axis',
        formatter: (params: unknown) => {
          const series = params as { dataIndex: number }[];
          const point = points[series[0]?.dataIndex ?? 0];

          return point === undefined ? '' : `${point.date}<br/><strong>${point.formatted}</strong>`;
        },
      },
      xAxis: categoryAxis({ locale, isRtl }, palette, {
        data: points.map((point) => point.date),
        // Day and month only: a 28-day axis has no room for full dates.
        format: (value: string) => value.slice(5),
      }),
      yAxis: valueAxis({ locale, isRtl }, palette, {
        format: (value: number) => formatCompact(value, locale),
      }),
      series: [
        lineSeries(palette, {
          name: t('overview.trend.title'),
          data: points.map((point) => point.value),
          area: true,
        }),
      ],
    };
  }, [points, isRtl, locale, t]);

  const source: MetricSourceValue = analytics.source;

  return (
    <ChartFrame
      title={t('overview.trend.title')}
      subtitle={t('overview.trend.subtitle')}
      source={source}
      isEmpty={points.every((point) => point.value === null)}
      emptyTitle={t('overview.trend.emptyTitle')}
      emptyBody={t('overview.trend.emptyBody')}
      table={{
        columns: [t('overview.trend.day'), t('overview.trend.spend')],
        rows: points.map((point) => ({
          key: point.date,
          label: point.date,
          cells: [point.formatted],
        })),
      }}
    >
      <Chart option={option} height={200} ariaLabel={t('overview.trend.aria')}>
        <ul>
          {points.map((point) => (
            <li key={point.date}>
              {point.date}: {point.formatted}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
