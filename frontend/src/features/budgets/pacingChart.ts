import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact } from '@/shared/lib/format';
import { fromMinor } from '@/shared/lib/money';
import { readChartPalette } from '@/shared/ui/chart/theme';
import type { PacingFigures } from './types';

type PacingChartContext = {
  locale: string;
  isRtl: boolean;
  currency: string;
  /** Translated labels, so the chart carries no English of its own. */
  labels: {
    actual: string;
    evenPace: string;
    budget: string;
  };
};

/**
 * The business question: **is this budget going to land where it was meant to?**
 *
 * Cumulative spend against the even pace, with the approved budget as a hard line. The
 * answer is the gap between two curves, read at today — which is why this is a chart
 * rather than the three numbers it is built from. A reader can see at a glance whether
 * the lines are converging or diverging, which no single percentage conveys.
 *
 * Three deliberate choices:
 *
 *  - **The actual line stops at the last day a provider reported.** It is not carried
 *    forward to today with a flat segment, because a flat cumulative line means "spent
 *    nothing since" and a missing sync means "we do not know".
 *  - **Days with no spend are skipped, not zeroed.** A cumulative series cannot dip, so a
 *    zero-filled day would flatten the slope and understate the rate.
 *  - **The even pace is drawn for the whole period**, including the future. That is the
 *    plan, and the plan exists for days that have not happened yet.
 */
/**
 * The two curves, as numbers.
 *
 * Exported so the chart's table twin reads the **same** arithmetic rather than a second
 * implementation of it. A cumulative series built twice is a cumulative series that disagrees with
 * itself the first time somebody changes one of them — and the two would be shown side by side,
 * which is the one place the disagreement is guaranteed to be noticed by a client.
 */
export function pacingSeries(
  daily: Array<{ date: string; spendMinor: number }>,
  pacing: PacingFigures,
  periodStart: string,
  periodEnd: string,
): { dates: string[]; actual: Array<number | null>; evenPace: number[] } {
  const dates = periodDates(periodStart, periodEnd);
  const spendByDate = new Map(daily.map((row) => [row.date, row.spendMinor]));

  const lastReported = daily.length === 0 ? null : (daily[daily.length - 1]?.date ?? null);

  let cumulative = 0;
  const actual: Array<number | null> = dates.map((date) => {
    const spend = spendByDate.get(date);

    if (spend !== undefined) {
      cumulative += spend;
    }

    /*
     * Past the last reported day the series ends. Null keeps the line from being drawn,
     * rather than extending it flat and claiming nothing has been spent since.
     */
    if (lastReported === null || date > lastReported) {
      return null;
    }

    return fromMinor(cumulative, pacing.currency);
  });

  const budgetMajor = fromMinor(pacing.budget_minor, pacing.currency);

  return {
    dates,
    actual,
    evenPace: dates.map((_, index) => ((index + 1) / dates.length) * budgetMajor),
  };
}

export function pacingOption(
  daily: Array<{ date: string; spendMinor: number }>,
  pacing: PacingFigures,
  periodStart: string,
  periodEnd: string,
  { isRtl, labels }: PacingChartContext,
): EChartsCoreOption {
  const palette = readChartPalette();
  const { dates, actual, evenPace } = pacingSeries(daily, pacing, periodStart, periodEnd);
  const budgetMajor = fromMinor(pacing.budget_minor, pacing.currency);

  return {
    // Actual and plan, two deliberately distinct series colours.
    color: [palette.series[0] ?? palette.accent, palette.series[2] ?? palette.threshold],
    textStyle: { fontFamily: palette.fontFamily, color: palette.textSecondary },
    grid: {
      top: 36,
      bottom: 28,
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
      // Solid: a dashed rule reads as a projection, and this one is a crosshair.
      axisPointer: { type: 'line', lineStyle: { color: palette.axis, width: 1, type: 'solid' } },
    },
    legend: {
      top: 0,
      icon: 'roundRect',
      itemWidth: 10,
      itemHeight: 10,
      textStyle: { color: palette.textSecondary, fontSize: 12, fontFamily: palette.fontFamily },
      data: [labels.actual, labels.evenPace],
    },
    xAxis: {
      type: 'category',
      data: dates,
      inverse: isRtl,
      boundaryGap: false,
      axisLine: { lineStyle: { color: palette.axis } },
      axisTick: { show: false },
      axisLabel: { color: palette.textSecondary, fontSize: 11, hideOverlap: true },
    },
    yAxis: {
      type: 'value',
      position: isRtl ? 'right' : 'left',
      // Solid hairlines. Dashing a grid adds noise and makes it read as a threshold.
      splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
      axisLabel: {
        color: palette.textSecondary,
        fontSize: 11,
        formatter: (value: number) => formatCompact(value, 'en'),
      },
    },
    series: [
      {
        name: labels.actual,
        type: 'line',
        data: actual,
        smooth: false,
        // Off, deliberately: a bridged gap says delivery continued when it stopped.
        connectNulls: false,
        showSymbol: false,
        lineStyle: { width: 2 },
        areaStyle: { opacity: 0.1 },
        markLine: {
          silent: true,
          symbol: 'none',
          label: {
            formatter: labels.budget,
            color: palette.textSecondary,
            fontSize: 11,
            position: isRtl ? 'insideStartTop' : 'insideEndTop',
          },
          lineStyle: { color: palette.axis, type: 'dashed', width: 1 },
          data: [{ yAxis: budgetMajor }],
        },
      },
      {
        name: labels.evenPace,
        type: 'line',
        data: evenPace,
        smooth: false,
        showSymbol: false,
        /*
         * Dashed, thin, and in the de-emphasis grey — the three things that say "this
         * line was calculated, not spent". Given a series colour it would read as a
         * second platform's spend, which is the one thing it must never be mistaken for.
         */
        itemStyle: { color: palette.comparison },
        lineStyle: { width: 1.5, type: 'dashed', color: palette.comparison },
      },
    ],
  };
}

/**
 * Every date in the period, inclusive.
 *
 * Built from the dates rather than taken from the spend rows, because the plan covers
 * days nothing was spent on and the chart has to show them.
 */
function periodDates(start: string, end: string): string[] {
  const dates: string[] = [];
  const cursor = new Date(`${start}T00:00:00Z`);
  const last = new Date(`${end}T00:00:00Z`);

  // A guard rather than a trust: a period of years would otherwise build a huge array.
  for (let guard = 0; guard < 400 && cursor <= last; guard += 1) {
    dates.push(cursor.toISOString().slice(0, 10));
    cursor.setUTCDate(cursor.getUTCDate() + 1);
  }

  return dates;
}
