import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact } from '@/shared/lib/format';
import { readChartPalette } from '@/shared/ui/chart/theme';
import { formatMetric } from './format';
import type { AnalyticsPayload, BreakdownRowPayload, MetricDefinition } from './types';

type Context = { locale: string; isRtl: boolean };

/**
 * Chart options for the widget types the dashboard builder offers.
 *
 * Separate from `charts.ts`, which holds the fixed charts on the analytics page.
 * These take whatever metric and dimension a reader chose, so they have to cope
 * with combinations nobody designed for — and the rule is the same as everywhere
 * else: a value that does not exist is absent, never zero.
 */
function numericValue(row: BreakdownRowPayload, metric: string): number | null {
  const value = row.values[metric];

  if (value === undefined || value.value === null) {
    return null;
  }

  return value.is_minor_units ? value.value / 100 : value.value;
}

function base(context: Context): EChartsCoreOption {
  const palette = readChartPalette();

  return {
    color: palette.series,
    textStyle: { fontFamily: palette.fontFamily, color: palette.textSecondary },
    grid: {
      top: 16,
      bottom: 24,
      left: context.isRtl ? 8 : 40,
      right: context.isRtl ? 40 : 8,
      containLabel: true,
    },
    tooltip: {
      backgroundColor: palette.tooltipSurface,
      borderColor: palette.tooltipBorder,
      borderWidth: 1,
      padding: [8, 12],
      textStyle: { color: palette.textPrimary, fontSize: 12, fontFamily: palette.fontFamily },
    },
  };
}

/** A metric across categories, as horizontal bars so the labels stay readable. */
export function widgetBarOption(
  rows: BreakdownRowPayload[],
  metric: string,
  definitions: Map<string, MetricDefinition>,
  context: Context,
): EChartsCoreOption {
  const palette = readChartPalette();
  // ECharts stacks a category axis bottom-up, so the largest goes last to sit on top.
  const ordered = [...rows].reverse();

  return {
    ...base(context),
    tooltip: {
      ...(base(context).tooltip as object),
      trigger: 'item',
      formatter: (params: unknown) => {
        const point = params as { dataIndex: number };
        const row = ordered[point.dataIndex];
        const value = row?.values[metric];

        return row === undefined || value === undefined
          ? ''
          : `${row.label}<br/><strong>${formatMetric(value, context.locale)}</strong>`;
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
        fontSize: 10,
        formatter: (value: number) => formatCompact(value, context.locale),
      },
    },
    yAxis: {
      type: 'category',
      position: context.isRtl ? 'right' : 'left',
      data: ordered.map((row) => row.label),
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: { color: palette.axisLabel, fontSize: 10, width: 110, overflow: 'truncate' },
    },
    series: [
      {
        name: definitions.get(metric)?.label ?? metric,
        type: 'bar',
        barMaxWidth: 16,
        itemStyle: { borderRadius: context.isRtl ? [3, 0, 0, 3] : [0, 3, 3, 0] },
        data: ordered.map((row) => numericValue(row, metric)),
      },
    ],
  };
}

/**
 * Share of a total, as a donut.
 *
 * Rows whose value is missing are dropped rather than shown as a zero-size slice:
 * a share chart implies the parts add to the whole, and a row that did not report
 * cannot be said to contribute nothing.
 */
export function widgetShareOption(
  rows: BreakdownRowPayload[],
  metric: string,
  context: Context,
): EChartsCoreOption {
  const palette = readChartPalette();

  const slices = rows
    .map((row) => ({ name: row.label, value: numericValue(row, metric) }))
    .filter(
      (slice): slice is { name: string; value: number } => slice.value !== null && slice.value > 0,
    );

  return {
    ...base(context),
    grid: undefined,
    tooltip: { ...(base(context).tooltip as object), trigger: 'item' },
    legend: {
      type: 'scroll',
      orient: 'vertical',
      left: context.isRtl ? 'left' : 'right',
      top: 'middle',
      itemWidth: 10,
      itemHeight: 10,
      textStyle: { color: palette.textSecondary, fontSize: 11 },
    },
    series: [
      {
        type: 'pie',
        // A donut rather than a pie: the hole makes the arc lengths easier to
        // compare than the areas of wedges meeting at a point.
        radius: ['45%', '72%'],
        center: context.isRtl ? ['65%', '50%'] : ['35%', '50%'],
        avoidLabelOverlap: true,
        itemStyle: { borderWidth: 2, borderColor: palette.tooltipSurface },
        label: { show: false },
        data: slices,
      },
    ],
  };
}

/** Two metrics against each other, one point per entity. */
export function widgetScatterOption(
  rows: BreakdownRowPayload[],
  metrics: [string, string],
  definitions: Map<string, MetricDefinition>,
  context: Context,
): EChartsCoreOption {
  const palette = readChartPalette();
  const [xMetric, yMetric] = metrics;

  const points = rows
    .map((row) => {
      const x = numericValue(row, xMetric);
      const y = numericValue(row, yMetric);

      // A point needs both coordinates. Substituting zero for a missing one parks it
      // on an axis and invites exactly the wrong conclusion.
      return x === null || y === null ? null : { name: row.label, value: [x, y] };
    })
    .filter((point): point is { name: string; value: number[] } => point !== null);

  const axis = (metric: string, position?: 'left' | 'right') => ({
    type: 'value' as const,
    name: definitions.get(metric)?.label ?? metric,
    nameTextStyle: { color: palette.axisLabel, fontSize: 10 },
    ...(position === undefined ? {} : { position: context.isRtl ? 'right' : position }),
    splitLine: { lineStyle: { color: palette.grid } },
    axisLine: { show: false },
    axisTick: { show: false },
    axisLabel: {
      color: palette.axisLabel,
      fontSize: 10,
      formatter: (value: number) => formatCompact(value, context.locale),
    },
  });

  return {
    ...base(context),
    tooltip: {
      ...(base(context).tooltip as object),
      trigger: 'item',
      formatter: (params: unknown) => {
        const point = params as { name: string; value: number[] };
        const [x = 0, y = 0] = point.value;

        return [
          `<strong>${point.name}</strong>`,
          `${definitions.get(xMetric)?.label ?? xMetric}: ${formatCompact(x, context.locale)}`,
          `${definitions.get(yMetric)?.label ?? yMetric}: ${formatCompact(y, context.locale)}`,
        ].join('<br/>');
      },
    },
    xAxis: { ...axis(xMetric), inverse: context.isRtl },
    yAxis: axis(yMetric, 'left'),
    series: [{ type: 'scatter', symbolSize: 10, itemStyle: { opacity: 0.75 }, data: points }],
  };
}

/**
 * One or two metrics over time.
 *
 * `connectNulls` stays off, as everywhere: a line drawn through a gap says delivery
 * continued when it had stopped.
 */
export function widgetLineOption(
  payload: AnalyticsPayload,
  metrics: string[],
  definitions: Map<string, MetricDefinition>,
  context: Context,
): EChartsCoreOption {
  const palette = readChartPalette();
  const points = context.isRtl ? [...payload.series].reverse() : payload.series;

  return {
    ...base(context),
    tooltip: { ...(base(context).tooltip as object), trigger: 'axis' },
    legend: {
      show: metrics.length > 1,
      top: 0,
      left: context.isRtl ? 'right' : 'left',
      icon: 'roundRect',
      itemWidth: 10,
      itemHeight: 10,
      textStyle: { color: palette.textSecondary, fontSize: 11 },
    },
    xAxis: {
      type: 'category',
      data: points.map((point) => point.date),
      boundaryGap: false,
      axisLine: { lineStyle: { color: palette.grid } },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 10,
        hideOverlap: true,
        formatter: (value: string) => value.slice(5),
      },
    },
    yAxis: {
      type: 'value',
      position: context.isRtl ? 'right' : 'left',
      splitLine: { lineStyle: { color: palette.grid } },
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: {
        color: palette.axisLabel,
        fontSize: 10,
        formatter: (value: number) => formatCompact(value, context.locale),
      },
    },
    series: metrics.map((metric, index) => ({
      name: definitions.get(metric)?.label ?? metric,
      type: 'line' as const,
      smooth: 0.2,
      showSymbol: false,
      connectNulls: false,
      lineStyle: { width: 2 },
      areaStyle: index === 0 ? { opacity: 0.08 } : { opacity: 0 },
      data: points.map((point) => {
        const value = point.values[metric];

        return value === undefined || value.value === null
          ? null
          : value.is_minor_units
            ? value.value / 100
            : value.value;
      }),
    })),
  };
}
