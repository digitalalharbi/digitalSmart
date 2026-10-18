import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact } from '@/shared/lib/format';
import { fromMinor } from '@/shared/lib/money';
import { foldToOther, readChartPalette, seriesColour } from '@/shared/ui/chart';
import { formatMetric } from './format';
import type { AnalyticsPayload, BreakdownRowPayload, MetricDefinition } from './types';

type Context = {
  locale: string;
  isRtl: boolean;
  /**
   * The few words these charts have to put on a canvas themselves.
   *
   * Passed in rather than translated here: this module has no component to hang a
   * translation hook on, and a chart that hard-codes English is a chart that is wrong
   * for half this product's readers.
   */
  labels: {
    /** Names the row that the folded tail was summed into. */
    other: string;
    /** Names an indexed axis, given the date the index is based on. */
    indexed: (date: string) => string;
  };
};

/** A donut stops being readable past this many slices. Past it, the tail folds. */
const MAX_SLICES = 6;

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

  return value.is_minor_units ? fromMinor(value.value, value.currency) : value.value;
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
      // Solid: a dashed rule reads as a projection or a threshold, and this is a crosshair.
      axisPointer: { type: 'line', lineStyle: { color: palette.axis, width: 1, type: 'solid' } },
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
      splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
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
        /*
         * One colour for every bar. The categories have no inherent order, so there is
         * nothing for a colour ramp to encode, and shading each bar by its own value
         * would spend the identity channel restating what bar length already says.
         */
        itemStyle: {
          color: seriesColour(palette, 0),
          borderRadius: context.isRtl ? [3, 0, 0, 3] : [0, 3, 3, 0],
        },
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

  const present = rows
    .map((row) => ({ name: row.label, value: numericValue(row, metric) }))
    .filter(
      (slice): slice is { name: string; value: number } => slice.value !== null && slice.value > 0,
    )
    .sort((a, b) => b.value - a.value);

  /*
   * Six slices, then one that says how much the rest came to.
   *
   * Hiding the tail would make the remaining slices look like the whole; giving each of
   * twenty rows its own colour would mean generated hues that a colour-blind reader
   * cannot separate. Summing it keeps both the readable chart and the honest total.
   */
  const { rows: slices, folded } = foldToOther(present, MAX_SLICES, (tail) => ({
    name: context.labels.other,
    value: tail.reduce((sum, slice) => sum + slice.value, 0),
  }));

  const colours = slices.map((_, index) =>
    // The folded row wears the de-emphasis grey: it is a residue, not a competitor.
    index === slices.length - 1 && folded > 0 ? palette.comparison : seriesColour(palette, index),
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
        // A 2px gap in the surface colour between slices, rather than a stroke around
        // each one: a border adds ink that is not data.
        itemStyle: { borderWidth: 2, borderColor: palette.surface },
        label: { show: false },
        data: slices.map((slice, index) => ({
          ...slice,
          itemStyle: { color: colours[index] },
        })),
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
    series: [
      {
        type: 'scatter',
        symbolSize: 11,
        // The ring keeps two entities at similar values from reading as one point, and
        // counts towards the hover target.
        itemStyle: {
          color: seriesColour(palette, 0),
          borderColor: palette.surface,
          borderWidth: 2,
          opacity: 0.85,
        },
        data: points,
      },
    ],
  };
}

/**
 * One or more metrics over time.
 *
 * **Several metrics are indexed to 100 at their common starting point, not plotted in
 * their own units.** A widget is too small for stacked plots, and the two alternatives
 * are both wrong: one shared axis flattens whichever metric is smaller into a line along
 * the floor, and a second axis invents a correlation, because where the two scales line
 * up is an arbitrary choice and every crossing it produces means nothing.
 *
 * Indexing says the only thing a small multi-metric chart can honestly say — which of
 * these moved, and by how much relative to where it started. The absolute figures are a
 * hover away, and in the table beside the chart.
 *
 * `connectNulls` stays off, as everywhere: a line drawn through a gap says delivery
 * continued when it had stopped. Nor is a line smoothed — a spline passes through values
 * nobody measured and overshoots the real extremes.
 */
export function widgetLineOption(
  payload: AnalyticsPayload,
  metrics: string[],
  definitions: Map<string, MetricDefinition>,
  context: Context,
): EChartsCoreOption {
  const palette = readChartPalette();
  const points = payload.series;
  const isIndexed = metrics.length > 1;

  const raw = metrics.map((metric) =>
    points.map((point) => {
      const value = point.values[metric];

      return value === undefined || value.value === null
        ? null
        : value.is_minor_units
          ? fromMinor(value.value, value.currency)
          : value.value;
    }),
  );

  /*
   * The first day on which every metric reported, which is the only base that lets the
   * series be compared. A base of zero cannot be indexed at all, so it is skipped too.
   */
  const baseIndex = points.findIndex((_, day) =>
    raw.every((values) => {
      const value = values[day];

      return value !== null && value !== undefined && value !== 0;
    }),
  );

  const plotted =
    isIndexed && baseIndex !== -1
      ? raw.map((values) =>
          values.map((value) => {
            const basis = values[baseIndex];

            return value === null || basis === null || basis === undefined || basis === 0
              ? null
              : (value / basis) * 100;
          }),
        )
      : raw;

  const indexedFrom = baseIndex === -1 ? null : (points[baseIndex]?.date ?? null);

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
      // Mirrored by inverting the axis, never by reversing the data: every index then
      // still means the day it says it means.
      inverse: context.isRtl,
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
      ...(isIndexed && indexedFrom !== null
        ? {
            // Named, because an unlabelled axis of numbers around 100 looks like a
            // percentage and is not one.
            name: context.labels.indexed(indexedFrom),
            nameTextStyle: { color: palette.axisLabel, fontSize: 10, padding: [0, 0, 4, 0] },
          }
        : {}),
      splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
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
      // See the note above the function: neither of these is a style preference.
      smooth: false,
      showSymbol: false,
      connectNulls: false,
      lineStyle: { width: 2 },
      itemStyle: { color: seriesColour(palette, index) },
      // A wash under a single series; nothing under several, where two overlapping
      // washes make a third colour that means nothing.
      areaStyle: metrics.length === 1 ? { opacity: 0.1 } : { opacity: 0 },
      data: plotted[index] ?? [],
    })),
  };
}
