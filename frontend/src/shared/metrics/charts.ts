import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact, formatNumber } from '@/shared/lib/format';
import { readChartPalette } from '@/shared/ui/chart/theme';
import type {
  AnalyticsPayload,
  BreakdownRowPayload,
  MetricDefinition,
  SeriesPointPayload,
} from './types';
import { formatMetric } from './format';

type ChartContext = {
  locale: string;
  isRtl: boolean;
};

/**
 * Shared chrome for every chart.
 *
 * Gridlines are the most over-weighted element in most dashboards: they are here
 * because a reader needs something to judge a value against, and they are faint
 * because the data should be the loudest thing on the canvas.
 *
 * In Arabic the whole chart mirrors — the value axis moves to the right and the
 * category axis reverses — because a time series that reads left-to-right in a
 * right-to-left interface asks the reader to change direction mid-page.
 */
function baseOption({ isRtl }: ChartContext): EChartsCoreOption {
  const palette = readChartPalette();

  return {
    color: palette.series,
    textStyle: { fontFamily: palette.fontFamily, color: palette.textSecondary },
    grid: {
      top: 28,
      bottom: 28,
      // Room for the value axis on whichever side it sits.
      left: isRtl ? 16 : 56,
      right: isRtl ? 56 : 16,
      containLabel: true,
    },
    tooltip: {
      trigger: 'axis',
      backgroundColor: palette.tooltipSurface,
      borderColor: palette.tooltipBorder,
      borderWidth: 1,
      padding: [8, 12],
      textStyle: { color: palette.textPrimary, fontSize: 12, fontFamily: palette.fontFamily },
      axisPointer: { type: 'line', lineStyle: { color: palette.axis, width: 1, type: 'dashed' } },
    },
    animationDuration: 220,
    animationEasing: 'cubicOut',
  };
}

/**
 * Spend and a second metric over time, as a line pair.
 *
 * Days with no data are `null`, not `0`, and the line is *broken* there rather
 * than drawn through: `connectNulls` is deliberately off. A line that bridges a
 * gap says delivery continued at some level, when in fact it stopped — the single
 * most misleading thing a performance chart can do.
 */
export function trendOption(
  payload: AnalyticsPayload,
  metrics: [string, string?],
  definitions: Map<string, MetricDefinition>,
  context: ChartContext,
): EChartsCoreOption {
  const palette = readChartPalette();
  const [primaryKey, secondaryKey] = metrics;
  const points = context.isRtl ? [...payload.series].reverse() : payload.series;

  const valueOf = (point: SeriesPointPayload, key: string): number | null => {
    const value = point.values[key];

    if (value === undefined || value.value === null) {
      return null;
    }

    return value.is_minor_units ? value.value / 100 : value.value;
  };

  const axisFor = (key: string | undefined, position: 'left' | 'right') => {
    if (key === undefined) {
      return null;
    }

    const definition = definitions.get(key);

    return {
      type: 'value' as const,
      name: definition?.label ?? key,
      nameTextStyle: { color: palette.axisLabel, fontSize: 11, padding: [0, 0, 6, 0] },
      position: context.isRtl ? (position === 'left' ? 'right' : 'left') : position,
      splitLine: { lineStyle: { color: palette.grid, width: 1 } },
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 11,
        formatter: (value: number) => formatCompact(value, context.locale),
      },
    };
  };

  const series = [
    {
      name: definitions.get(primaryKey)?.label ?? primaryKey,
      type: 'line' as const,
      yAxisIndex: 0,
      smooth: 0.2,
      showSymbol: false,
      // Off on purpose: see the note above. A broken line is the honest shape.
      connectNulls: false,
      lineStyle: { width: 2 },
      areaStyle: { opacity: 0.08 },
      data: points.map((point) => valueOf(point, primaryKey)),
    },
  ];

  if (secondaryKey !== undefined) {
    series.push({
      name: definitions.get(secondaryKey)?.label ?? secondaryKey,
      type: 'line' as const,
      yAxisIndex: 1,
      smooth: 0.2,
      showSymbol: false,
      connectNulls: false,
      lineStyle: { width: 2 },
      areaStyle: { opacity: 0 },
      data: points.map((point) => valueOf(point, secondaryKey)),
    });
  }

  const yAxis = [axisFor(primaryKey, 'left'), axisFor(secondaryKey, 'right')].filter(
    (axis): axis is NonNullable<typeof axis> => axis !== null,
  );

  return {
    ...baseOption(context),
    legend: {
      show: series.length > 1,
      top: 0,
      // Follows the reading direction, like everything else on the page.
      left: context.isRtl ? 'right' : 'left',
      icon: 'roundRect',
      itemWidth: 10,
      itemHeight: 10,
      textStyle: { color: palette.textSecondary, fontSize: 12 },
    },
    xAxis: {
      type: 'category',
      data: points.map((point) => point.date),
      boundaryGap: false,
      axisLine: { lineStyle: { color: palette.grid } },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 11,
        // A 90-day range has no room for 90 labels; ECharts thins them out.
        hideOverlap: true,
        formatter: (value: string) => value.slice(5),
      },
    },
    yAxis,
    series,
    dataZoom: [
      // Brushing a time series is how someone investigates a spike, so it is
      // available on the chart itself rather than only through the date filter.
      { type: 'inside', throttle: 50 },
    ],
  };
}

/**
 * A breakdown as horizontal bars.
 *
 * Horizontal, not vertical, because the labels are campaign names: vertical bars
 * would force them to 45° or truncate them, and a chart whose categories cannot be
 * read is a decorative chart.
 */
export function breakdownOption(
  rows: BreakdownRowPayload[],
  metric: string,
  definitions: Map<string, MetricDefinition>,
  context: ChartContext,
): EChartsCoreOption {
  const palette = readChartPalette();
  const definition = definitions.get(metric);

  // ECharts stacks a category axis bottom-up, so the largest value has to go last
  // to appear at the top.
  const ordered = [...rows].reverse();

  return {
    ...baseOption(context),
    grid: { top: 8, bottom: 24, left: 8, right: 8, containLabel: true },
    tooltip: {
      ...(baseOption(context).tooltip as object),
      trigger: 'item',
      formatter: (params: unknown) => {
        const point = params as { dataIndex: number };
        const row = ordered[point.dataIndex];
        const value = row?.values[metric];

        if (row === undefined || value === undefined) {
          return '';
        }

        return `${row.label}<br/><strong>${formatMetric(value, context.locale)}</strong>`;
      },
    },
    xAxis: {
      type: 'value',
      inverse: context.isRtl,
      splitLine: { lineStyle: { color: palette.grid } },
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 11,
        formatter: (value: number) => formatCompact(value, context.locale),
      },
    },
    yAxis: {
      type: 'category',
      position: context.isRtl ? 'right' : 'left',
      data: ordered.map((row) => row.label),
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 11,
        width: 160,
        overflow: 'truncate',
      },
    },
    series: [
      {
        name: definition?.label ?? metric,
        type: 'bar',
        barMaxWidth: 18,
        itemStyle: { borderRadius: context.isRtl ? [4, 0, 0, 4] : [0, 4, 4, 0] },
        data: ordered.map((row) => {
          const value = row.values[metric];

          if (value === undefined || value.value === null) {
            return null;
          }

          return value.is_minor_units ? value.value / 100 : value.value;
        }),
      },
    ],
  };
}

/**
 * Efficiency against scale, as a scatter.
 *
 * The chart that answers "which campaigns are worth more money?" — cheap and
 * small is a candidate to scale, expensive and large is a candidate to cut, and
 * neither is visible on a bar chart of either axis alone.
 */
export function efficiencyOption(
  rows: BreakdownRowPayload[],
  scaleMetric: string,
  efficiencyMetric: string,
  definitions: Map<string, MetricDefinition>,
  context: ChartContext,
): EChartsCoreOption {
  const palette = readChartPalette();

  const numeric = (row: BreakdownRowPayload, key: string): number | null => {
    const value = row.values[key];

    if (value === undefined || value.value === null) {
      return null;
    }

    return value.is_minor_units ? value.value / 100 : value.value;
  };

  const points = rows
    .map((row) => {
      const x = numeric(row, scaleMetric);
      const y = numeric(row, efficiencyMetric);

      // A point needs both coordinates. Substituting zero for a missing one would
      // park it on an axis and invite exactly the wrong conclusion.
      return x === null || y === null ? null : { value: [x, y], name: row.label };
    })
    .filter((point): point is { value: number[]; name: string } => point !== null);

  return {
    ...baseOption(context),
    grid: { top: 24, bottom: 36, left: 56, right: 24, containLabel: true },
    tooltip: {
      ...(baseOption(context).tooltip as object),
      trigger: 'item',
      formatter: (params: unknown) => {
        const point = params as { name: string; value: number[] };
        const [x = 0, y = 0] = point.value;

        return [
          `<strong>${point.name}</strong>`,
          `${definitions.get(scaleMetric)?.label ?? scaleMetric}: ${formatNumber(x, context.locale, 0)}`,
          `${definitions.get(efficiencyMetric)?.label ?? efficiencyMetric}: ${formatNumber(y, context.locale, 2)}`,
        ].join('<br/>');
      },
    },
    xAxis: {
      type: 'value',
      name: definitions.get(scaleMetric)?.label ?? scaleMetric,
      nameLocation: 'middle',
      nameGap: 26,
      nameTextStyle: { color: palette.axisLabel, fontSize: 11 },
      inverse: context.isRtl,
      splitLine: { lineStyle: { color: palette.grid } },
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 11,
        formatter: (value: number) => formatCompact(value, context.locale),
      },
    },
    yAxis: {
      type: 'value',
      name: definitions.get(efficiencyMetric)?.label ?? efficiencyMetric,
      position: context.isRtl ? 'right' : 'left',
      nameTextStyle: { color: palette.axisLabel, fontSize: 11 },
      splitLine: { lineStyle: { color: palette.grid } },
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 11,
        formatter: (value: number) => formatCompact(value, context.locale),
      },
    },
    series: [
      {
        type: 'scatter',
        symbolSize: 12,
        itemStyle: { opacity: 0.75 },
        data: points,
      },
    ],
  };
}

/**
 * The pair a trend chart plots: the chosen metric, and spend for context.
 *
 * Spend is the second line on almost every chart in this product because every
 * other figure means something different depending on what was spent to get it —
 * a CTR rising while spend collapses is not the same story as both rising.
 */
export function trendMetricsFor(metric: string): [string, string?] {
  return metric === 'spend' ? ['spend', 'roas'] : [metric, 'spend'];
}
