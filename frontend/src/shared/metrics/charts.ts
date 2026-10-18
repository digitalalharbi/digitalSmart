import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact, formatNumber } from '@/shared/lib/format';
import { fromMinor } from '@/shared/lib/money';
import {
  barSeries,
  baseOption,
  categoryAxis,
  legend,
  lineSeries,
  readChartPalette,
  seriesColour,
  valueAxis,
  type ChartContext,
} from '@/shared/ui/chart';
import type {
  AnalyticsPayload,
  BreakdownRowPayload,
  MetricDefinition,
  SeriesPointPayload,
} from './types';
import { formatMetric } from './format';

/** The plotted value for a metric, in display units rather than minor units. */
function valueOf(point: SeriesPointPayload, key: string): number | null {
  const value = point.values[key];

  if (value === undefined || value.value === null) {
    return null;
  }

  return value.is_minor_units ? fromMinor(value.value, value.currency) : value.value;
}

function rowValue(row: BreakdownRowPayload, key: string): number | null {
  const value = row.values[key];

  if (value === undefined || value.value === null) {
    return null;
  }

  return value.is_minor_units ? fromMinor(value.value, value.currency) : value.value;
}

/**
 * A metric over time, with spend beneath it as context.
 *
 * **Two stacked plots sharing one time axis, not two scales on one plot.** The second
 * scale is the single most misleading thing a performance chart can do: where the two
 * axes are aligned is an arbitrary choice, and whatever that choice is, the lines appear
 * to cross and diverge at moments that mean nothing. A reader takes a correlation off
 * the chart that is not in the data — and acts on it, because "conversions track spend"
 * is exactly the sort of claim a budget gets approved on.
 *
 * Stacked plots keep both series in their own units with their own baseline, and one
 * crosshair runs through both, so the comparison a reader actually wants — what was
 * happening to the other number on the day this one moved — is still one glance.
 *
 * Days with no data are `null` and the line breaks there. See `marks.ts`.
 */
export function trendOption(
  payload: AnalyticsPayload,
  metrics: [string, string?],
  definitions: Map<string, MetricDefinition>,
  context: ChartContext,
): EChartsCoreOption {
  const palette = readChartPalette();
  const [primaryKey, secondaryKey] = metrics;
  const points = payload.series;
  const dates = points.map((point) => point.date);
  const labelOf = (key: string): string => definitions.get(key)?.label ?? key;
  const compact = (value: number): string => formatCompact(value, context.locale);

  const base = baseOption(context, palette);

  if (secondaryKey === undefined) {
    return {
      ...base,
      legend: legend(context, palette, 1),
      xAxis: categoryAxis(context, palette, {
        data: dates,
        format: (value: string) => value.slice(5),
      }),
      yAxis: valueAxis(context, palette, { name: labelOf(primaryKey), format: compact }),
      series: [
        lineSeries(palette, {
          name: labelOf(primaryKey),
          data: points.map((point) => valueOf(point, primaryKey)),
          area: true,
        }),
      ],
      // Brushing a time series is how somebody investigates a spike, so it is on the
      // chart itself rather than only in the date filter above it.
      dataZoom: [{ type: 'inside', throttle: 50, xAxisIndex: 0 }],
    };
  }

  const gutter = { left: context.isRtl ? 16 : 56, right: context.isRtl ? 56 : 16 };

  return {
    ...base,
    grid: [
      { top: 28, height: '48%', ...gutter, containLabel: true },
      { top: '64%', bottom: 28, ...gutter, containLabel: true },
    ],
    legend: legend(context, palette, 2),
    /*
     * One crosshair across both plots. Without the link a reader has to hover twice and
     * hold one number in their head, which is the work the chart was supposed to do.
     */
    axisPointer: { link: [{ xAxisIndex: 'all' }] },
    tooltip: { ...(base.tooltip as object), trigger: 'axis' },
    xAxis: [
      // The upper plot's dates are the lower plot's dates, so only one set is labelled.
      categoryAxis(context, palette, { data: dates, gridIndex: 0, showLabels: false }),
      categoryAxis(context, palette, {
        data: dates,
        gridIndex: 1,
        format: (value: string) => value.slice(5),
      }),
    ],
    yAxis: [
      valueAxis(context, palette, { name: labelOf(primaryKey), format: compact, gridIndex: 0 }),
      valueAxis(context, palette, { name: labelOf(secondaryKey), format: compact, gridIndex: 1 }),
    ],
    series: [
      lineSeries(palette, {
        name: labelOf(primaryKey),
        data: points.map((point) => valueOf(point, primaryKey)),
        area: true,
        xAxisIndex: 0,
        yAxisIndex: 0,
      }),
      lineSeries(palette, {
        name: labelOf(secondaryKey),
        data: points.map((point) => valueOf(point, secondaryKey)),
        colour: seriesColour(palette, 1),
        xAxisIndex: 1,
        yAxisIndex: 1,
      }),
    ],
    dataZoom: [{ type: 'inside', throttle: 50, xAxisIndex: [0, 1] }],
  };
}

/**
 * A breakdown as horizontal bars.
 *
 * Horizontal because the labels are campaign names: columns would force them to 45° or
 * truncate them, and a chart whose categories cannot be read is decoration.
 *
 * **One colour for every bar.** The categories are campaigns — swapping two of them
 * changes nothing — so there is no order for a colour ramp to encode, and shading each
 * bar by its own value would spend the identity channel restating what bar length
 * already says.
 */
export function breakdownOption(
  rows: BreakdownRowPayload[],
  metric: string,
  definitions: Map<string, MetricDefinition>,
  context: ChartContext,
): EChartsCoreOption {
  const palette = readChartPalette();
  const definition = definitions.get(metric);

  // ECharts stacks a category axis bottom-up, so the largest value goes last to appear
  // at the top. `categoryAxis` must not re-reverse it, hence the explicit data here.
  const ordered = [...rows].reverse();
  const base = baseOption(context, palette);

  return {
    ...base,
    grid: { top: 8, bottom: 24, left: 8, right: 8, containLabel: true },
    tooltip: {
      ...(base.tooltip as object),
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
      splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
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
        ...barSeries(palette, {
          name: definition?.label ?? metric,
          data: ordered.map((row) => rowValue(row, metric)),
          orientation: 'horizontal',
          isRtl: context.isRtl,
          colour: seriesColour(palette, 0),
        }),
        /*
         * A value at the tip of each bar. Sparing enough to work here — one number per
         * row, outside the mark, so nothing is ever clipped by a bar too short to hold
         * its own label.
         */
        label: {
          show: true,
          position: context.isRtl ? 'left' : 'right',
          color: palette.textSecondary,
          fontSize: 11,
          formatter: (params: unknown) => {
            const point = params as { dataIndex: number };
            const value = ordered[point.dataIndex]?.values[metric];

            return value === undefined ? '' : formatMetric(value, context.locale);
          },
        },
      },
    ],
  };
}

/**
 * Efficiency against scale, as a scatter.
 *
 * The chart that answers "which campaigns are worth more money?" — cheap and small is a
 * candidate to scale, expensive and large is a candidate to cut, and neither is visible
 * on a bar chart of either axis alone.
 */
export function efficiencyOption(
  rows: BreakdownRowPayload[],
  scaleMetric: string,
  efficiencyMetric: string,
  definitions: Map<string, MetricDefinition>,
  context: ChartContext,
): EChartsCoreOption {
  const palette = readChartPalette();
  const base = baseOption(context, palette);

  const points = rows
    .map((row) => {
      const x = rowValue(row, scaleMetric);
      const y = rowValue(row, efficiencyMetric);

      // A point needs both coordinates. Substituting zero for a missing one would park
      // it on an axis and invite exactly the wrong conclusion.
      return x === null || y === null ? null : { value: [x, y], name: row.label };
    })
    .filter((point): point is { value: number[]; name: string } => point !== null);

  const axis = (metric: string, side: 'near' | 'far') => ({
    ...valueAxis(context, palette, {
      name: definitions.get(metric)?.label ?? metric,
      format: (value: number) => formatCompact(value, context.locale),
      side,
    }),
  });

  return {
    ...base,
    grid: { top: 24, bottom: 36, left: 56, right: 24, containLabel: true },
    tooltip: {
      ...(base.tooltip as object),
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
      ...axis(scaleMetric, 'near'),
      inverse: context.isRtl,
      nameLocation: 'middle',
      nameGap: 26,
    },
    yAxis: axis(efficiencyMetric, 'near'),
    series: [
      {
        type: 'scatter',
        /*
         * Larger than the 8px floor, with a ring in the surface colour: points overlap
         * on a scatter, and the ring is what keeps two campaigns at a similar cost from
         * reading as one. It is also part of the hover target.
         */
        symbolSize: 12,
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
 * The pair a trend chart plots: the chosen metric, and spend for context.
 *
 * Spend is the second plot on almost every chart in this product because every other
 * figure means something different depending on what was spent to get it — a CTR rising
 * while spend collapses is not the same story as both rising.
 */
export function trendMetricsFor(metric: string): [string, string?] {
  return metric === 'spend' ? ['spend', 'roas'] : [metric, 'spend'];
}
