/**
 * The shape of every chart in this product.
 *
 * Mark sizes, gaps and chrome weights are fixed here rather than chosen per chart, for
 * the same reason the colours are: a dashboard whose nine charts each made their own
 * decisions reads as nine products. The specifics — a 24px cap on bar thickness, a 2px
 * gap in the surface colour between touching marks, hairline solid gridlines — are the
 * difference between a chart that looks considered and one that looks loud.
 *
 * Two of these are honesty rules rather than taste:
 *
 *  - **A gap is never drawn through.** A day with no data is `null` and the line breaks
 *    there. Bridging it says delivery continued at some level when it stopped.
 *  - **A line is smoothed only monotonically.** The soft curve the rest of the product
 *    uses is drawn with ECharts' monotone mode, which between two readings only ever
 *    runs between them. A free spline would overshoot the real extremes and let a
 *    reader take a peak off the chart that is not in the data; this cannot.
 */
import type { EChartsCoreOption } from 'echarts/core';
import type { ChartPalette } from './theme';
import { readChartPalette, seriesCap as SERIES_CAP } from './theme';

/** Fixed mark geometry. Exported so a chart can measure against it, not redefine it. */
export const MARK = {
  /** Never let a bar fill its band: the leftover space is what makes bars readable. */
  barMaxWidth: 24,
  lineWidth: 2,
  /** The rounded, soft line of the product's chart language. Monotone — see the note above. */
  smooth: 0.3,
  /** Big enough to see, and big enough to be a hover target with its ring. */
  symbolSize: 9,
  /** White doing the separating, instead of a border drawn around each mark. */
  surfaceGap: 2,
  /** A wash, never a saturated block. */
  areaOpacity: 0.14,
  /**
   * The radius on a bar's data end.
   *
   * Four pixels: enough to read as a deliberate shape at the sizes these charts use, small enough that a
   * short bar is still a bar rather than a lozenge. The baseline end stays square — see `barSeries`.
   */
  barRadius: 6,
} as const;

export type ChartContext = {
  locale: string;
  isRtl: boolean;
};

/**
 * Chrome shared by every chart.
 *
 * In Arabic the whole chart mirrors — the value axis moves to the right and the category
 * axis reverses — because a time series that reads left to right inside a right-to-left
 * page asks the reader to change direction halfway down it.
 */
export function baseOption(
  context: ChartContext,
  palette: ChartPalette = readChartPalette(),
): EChartsCoreOption {
  return {
    color: palette.series,
    textStyle: { fontFamily: palette.fontFamily, color: palette.textSecondary },
    /*
     * Room above the plot for the legend, and room below for a date axis. Charts in this product sit inside a
     * card with its own padding, so the grid's job is only to keep the marks off the axis labels — not to add
     * a second margin, which is what made earlier charts look cramped inside generous cards.
     */
    grid: {
      top: 32,
      bottom: 24,
      left: context.isRtl ? 8 : 48,
      right: context.isRtl ? 48 : 8,
      containLabel: true,
    },
    tooltip: {
      trigger: 'axis',
      backgroundColor: palette.tooltipSurface,
      borderColor: palette.tooltipBorder,
      borderWidth: 1,
      /* A card, not a label: the tooltip is the one chart surface a reader looks *at* rather than through. */
      borderRadius: 12,
      padding: [10, 14],
      extraCssText: 'box-shadow: var(--ds-shadow-md); backdrop-filter: blur(8px);',
      textStyle: { color: palette.textPrimary, fontSize: 13, fontFamily: palette.fontFamily },
      /*
       * Solid, not dashed. A dashed rule reads as a projection or a threshold; this is
       * a crosshair, and it should look like one.
       */
      axisPointer: { type: 'line', lineStyle: { color: palette.axis, width: 1, type: 'solid' } },
    },
    animationDuration: 220,
    animationEasing: 'cubicOut',
  };
}

/** A value axis, on the side the reading direction puts it. */
export function valueAxis(
  context: ChartContext,
  palette: ChartPalette,
  options: {
    name?: string;
    format: (value: number) => string;
    side?: 'near' | 'far';
    gridIndex?: number;
  },
) {
  const near = context.isRtl ? 'right' : 'left';
  const far = context.isRtl ? 'left' : 'right';

  return {
    type: 'value' as const,
    ...(options.gridIndex === undefined ? {} : { gridIndex: options.gridIndex }),
    ...(options.name === undefined ? {} : { name: options.name }),
    nameTextStyle: { color: palette.axisLabel, fontSize: 11, padding: [0, 0, 6, 0] },
    position: options.side === 'far' ? far : near,
    /*
     * Faint and dashed, like the rest of the product's charts. A dashed rule could be mistaken for a threshold, so
     * real thresholds (`thresholdLine`) are drawn solid and heavier — the grid is the quiet one.
     */
    splitLine: { lineStyle: { color: palette.grid, width: 1, type: [3, 3] as [number, number] } },
    axisLine: { show: false },
    axisTick: { show: false },
    axisLabel: {
      color: palette.axisLabel,
      fontSize: 12,
      formatter: options.format,
    },
  };
}

/** A category axis along the bottom. */
export function categoryAxis(
  _context: ChartContext,
  palette: ChartPalette,
  options: {
    data: string[];
    gridIndex?: number;
    showLabels?: boolean;
    format?: (value: string) => string;
    boundaryGap?: boolean;
  },
) {
  return {
    type: 'category' as const,
    data: options.data,
    /*
     * Time runs left to right in Arabic as well. A timeline that reversed with the page would put last week on the
     * wrong side of this week for anybody reading a chart from a screenshot; the labels and tooltips localise
     * instead. (Earlier builds inverted this axis, by index and never by reversing data, for the opposite policy.)
     */
    inverse: false,
    ...(options.gridIndex === undefined ? {} : { gridIndex: options.gridIndex }),
    boundaryGap: options.boundaryGap ?? false,
    /* No baseline rule: the bottom-most grid line already marks the floor of the plot. */
    axisLine: { show: false },
    axisTick: { show: false },
    axisLabel: {
      show: options.showLabels ?? true,
      color: palette.axisLabel,
      fontSize: 12,
      // A 90-day range has no room for 90 labels, so ECharts thins them out.
      hideOverlap: true,
      ...(options.format === undefined ? {} : { formatter: options.format }),
    },
  };
}

/**
 * A legend — present whenever there is more than one series.
 *
 * Identity never rests on colour alone: that is the point of the legend, and it is why
 * there is no option to switch it off for a multi-series chart. A single series gets
 * none, because the chart's own heading already says what is plotted and a box with one
 * swatch in it just restates the title.
 */
export function legend(context: ChartContext, palette: ChartPalette, seriesCount: number) {
  return {
    show: seriesCount > 1,
    top: 0,
    left: context.isRtl ? 'right' : 'left',
    icon: 'circle',
    itemWidth: 10,
    itemHeight: 10,
    textStyle: { color: palette.textSecondary, fontSize: 13 },
  };
}

type LineSeriesOptions = {
  name: string;
  data: Array<number | null>;
  colour?: string;
  area?: boolean;
  xAxisIndex?: number;
  yAxisIndex?: number;
};

/** A time series as a line. Broken where data is missing; softened, never overshooting. */
export function lineSeries(palette: ChartPalette, options: LineSeriesOptions) {
  return {
    name: options.name,
    type: 'line' as const,
    ...(options.xAxisIndex === undefined ? {} : { xAxisIndex: options.xAxisIndex }),
    ...(options.yAxisIndex === undefined ? {} : { yAxisIndex: options.yAxisIndex }),
    // See the note at the top of this file: neither of these is a style preference.
    smooth: MARK.smooth,
    smoothMonotone: 'x' as const,
    connectNulls: false,
    showSymbol: false,
    symbolSize: MARK.symbolSize,
    lineStyle: { width: MARK.lineWidth, cap: 'round' as const, join: 'round' as const },
    ...(options.colour === undefined ? {} : { itemStyle: { color: options.colour } }),
    ...(options.area === true
      ? {
          /*
           * A wash that is strongest at the line and gone at the floor — the soft gradient of the product's chart
           * language. It needs the series' own colour, so without one it falls back to a flat, faint fill.
           */
          areaStyle:
            options.colour === undefined
              ? { opacity: MARK.areaOpacity }
              : {
                  color: {
                    type: 'linear' as const,
                    x: 0,
                    y: 0,
                    x2: 0,
                    y2: 1,
                    colorStops: [
                      { offset: 0, color: options.colour },
                      { offset: 1, color: 'transparent' },
                    ],
                  },
                  opacity: 0.28,
                },
        }
      : { areaStyle: { opacity: 0 } }),
    /*
     * The ring is in the surface colour so a marker stays legible where it crosses
     * another line, and it counts towards the hover target rather than only the look.
     */
    emphasis: { itemStyle: { borderColor: palette.surface, borderWidth: MARK.surfaceGap } },
    data: options.data,
  };
}

type BarSeriesOptions = {
  name: string;
  data: Array<number | null>;
  colour?: string;
  /** Horizontal bars grow from the start edge; columns grow from the baseline. */
  orientation: 'horizontal' | 'vertical';
  isRtl: boolean;
  stack?: string;
  /** Per-datum colours, for an ordinal scale where each bar is a stage. */
  colours?: string[];
};

/**
 * A bar or column.
 *
 * Rounded at the data end and square at the baseline, so the mark visibly grows from
 * the axis rather than floating. The gap between touching bars is drawn in the surface
 * colour instead of a border: a stroke adds ink that is not data.
 */
export function barSeries(palette: ChartPalette, options: BarSeriesOptions) {
  const radius = MARK.barRadius;
  const borderRadius =
    options.orientation === 'horizontal'
      ? options.isRtl
        ? ([radius, 0, 0, radius] as const)
        : ([0, radius, radius, 0] as const)
      : ([radius, radius, 0, 0] as const);

  return {
    name: options.name,
    type: 'bar' as const,
    barMaxWidth: MARK.barMaxWidth,
    ...(options.stack === undefined ? {} : { stack: options.stack }),
    itemStyle: {
      borderRadius: [...borderRadius],
      ...(options.colour === undefined ? {} : { color: options.colour }),
      /*
       * The 2px separator, as a border in the surface colour. On a stack this keeps the
       * segments apart; on adjacent bars it keeps neighbouring hues from bleeding.
       */
      borderColor: palette.surface,
      borderWidth: options.stack === undefined ? 0 : MARK.surfaceGap,
    },
    data:
      options.colours === undefined
        ? options.data
        : options.data.map((value, index) => ({
            value,
            itemStyle: { color: options.colours?.[index] ?? palette.series[0] },
          })),
  };
}

/**
 * A horizontal rule at a reference value — a target, a limit, a baseline.
 *
 * Deliberately not a series: a target is not data, and a reader who can toggle it off
 * in the legend will mistake it for one.
 */
export function thresholdLine(palette: ChartPalette, value: number, label: string) {
  return {
    silent: true,
    symbol: 'none',
    label: {
      formatter: label,
      position: 'insideEndTop' as const,
      color: palette.textTertiary,
      fontSize: 11,
    },
    lineStyle: { color: palette.threshold, width: 1.5, type: 'solid' as const },
    data: [{ yAxis: value }],
  };
}

/**
 * The colour for one series, by its position in the sequence.
 *
 * Slots are handed out in order and never skipped, because the order is what the
 * colour-blind separation was measured on: slot 3 is only ever beside slots 2 and 4.
 *
 * Past the cap it returns the last slot, so two series share a colour. That is a visible
 * bug, which is the point — a generated ninth hue would look plausible and be
 * indistinguishable from an existing slot to a colour-blind reader. Fold the tail with
 * `foldToOther` before plotting instead.
 */
export function seriesColour(
  palette: ChartPalette,
  index: number,
  form: keyof typeof SERIES_CAP = 'adjacent',
): string {
  const slot = Math.min(index, SERIES_CAP[form] - 1);

  return palette.series[slot] ?? palette.series[0] ?? '#c24a2c';
}

/** A step of the ordinal ramp, for a scale whose order is its meaning. */
export function stepColour(palette: ChartPalette, index: number, total: number): string {
  // Spread across the ramp rather than taking the first N, so a three-stage funnel uses
  // the whole range instead of three colours that barely differ.
  const steps = palette.steps;
  const position = total <= 1 ? 0 : Math.round((index / (total - 1)) * (steps.length - 1));

  return steps[position] ?? steps[0] ?? '#5985e4';
}

/**
 * Keep the biggest rows and sum the rest into one named row.
 *
 * The honest alternative to a ninth colour, and the honest alternative to hiding the
 * tail: a reader can see that there *is* a tail and how big it is. Rows are expected in
 * descending order of whatever the chart plots.
 */
export function foldToOther<T>(
  rows: T[],
  limit: number,
  fold: (tail: T[]) => T,
): { rows: T[]; folded: number } {
  if (rows.length <= limit) {
    return { rows, folded: 0 };
  }

  const kept = rows.slice(0, limit - 1);
  const tail = rows.slice(limit - 1);

  return { rows: [...kept, fold(tail)], folded: tail.length };
}
