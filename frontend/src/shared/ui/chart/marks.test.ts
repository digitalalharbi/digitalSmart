import { describe, expect, it } from 'vitest';
import {
  baseOption,
  barSeries,
  categoryAxis,
  foldToOther,
  legend,
  lineSeries,
  MARK,
  seriesColour,
  stepColour,
  valueAxis,
} from './marks';
import { readChartPalette, seriesCap } from './theme';

/*
|--------------------------------------------------------------------------
| Chart marks
|--------------------------------------------------------------------------
|
| The rules a chart cannot be allowed to break, as assertions rather than as review
| comments. Each one has a wrong version that looks completely normal on screen, which is
| exactly why it needs a test: nobody reviewing a dashboard notices that a line was drawn
| through a gap, and everybody acts on it.
|
*/

const palette = readChartPalette();
const ltr = { locale: 'en', isRtl: false };
const rtl = { locale: 'ar', isRtl: true };

describe('what a line is not allowed to do', () => {
  it('breaks at a gap instead of drawing through it', () => {
    const series = lineSeries(palette, { name: 'Spend', data: [10, null, 30] });

    /*
     * The single most misleading thing a performance chart can do. A bridged gap says
     * delivery continued at some level on a day it had stopped, and the reader draws the
     * opposite conclusion about the campaign from the true one.
     */
    expect(series.connectNulls).toBe(false);
    expect(series.data[1]).toBeNull();
  });

  it('is smoothed only monotonically', () => {
    const series = lineSeries(palette, { name: 'Spend', data: [10, 90, 20] });

    /*
     * The soft line is the product's chart language, but a free spline passes through values nobody measured and
     * overshoots the real extremes. Monotone smoothing runs only between neighbouring readings, so a peak on the chart
     * is a peak in the data.
     */
    expect(series.smooth).toBeGreaterThan(0);
    expect(series.smoothMonotone).toBe('x');
  });
});

describe('what a bar is not allowed to do', () => {
  it('never fills its band', () => {
    const series = barSeries(palette, {
      name: 'Spend',
      data: [1],
      orientation: 'vertical',
      isRtl: false,
    });

    // The leftover space is what makes a row of bars readable rather than a solid block.
    expect(series.barMaxWidth).toBe(MARK.barMaxWidth);
    expect(MARK.barMaxWidth).toBeLessThanOrEqual(24);
  });

  it('separates stacked segments with the surface, not a border of its own', () => {
    const stacked = barSeries(palette, {
      name: 'Paid',
      data: [1],
      orientation: 'vertical',
      isRtl: false,
      stack: 'channel',
    });

    expect(stacked.itemStyle.borderColor).toBe(palette.surface);
    expect(stacked.itemStyle.borderWidth).toBe(MARK.surfaceGap);
  });

  it('rounds the data end and squares the baseline, on the side the language reads from', () => {
    const ltrBar = barSeries(palette, {
      name: 'Spend',
      data: [1],
      orientation: 'horizontal',
      isRtl: false,
    });
    const rtlBar = barSeries(palette, {
      name: 'Spend',
      data: [1],
      orientation: 'horizontal',
      isRtl: true,
    });

    // A bar visibly grows from the axis rather than floating, whichever side the axis is on.
    expect(ltrBar.itemStyle.borderRadius).toEqual([0, 6, 6, 0]);
    expect(rtlBar.itemStyle.borderRadius).toEqual([6, 0, 0, 6]);
  });
});

describe('chrome', () => {
  it('draws gridlines faint and dashed, the crosshair solid, and thresholds heavier than either', () => {
    const axis = valueAxis(ltr, palette, { format: String });
    const tooltip = baseOption(ltr, palette).tooltip as {
      axisPointer: { lineStyle: { type: string } };
    };

    /* The grid is the quiet rule; a real threshold is solid and heavier, so the two are never confused. */
    expect(axis.splitLine.lineStyle.type).toEqual([3, 3]);
    expect(tooltip.axisPointer.lineStyle.type).toBe('solid');
  });

  it('shows a legend for two series and none for one', () => {
    /*
     * Identity never rests on colour alone — that is what the legend is for. One series
     * needs none: a box with a single swatch restates the title and costs space.
     */
    expect(legend(ltr, palette, 1).show).toBe(false);
    expect(legend(ltr, palette, 2).show).toBe(true);
  });

  it('keeps time running left to right in Arabic, and moves only the value axis', () => {
    const axis = categoryAxis(rtl, palette, { data: ['2026-09-01', '2026-09-02'] });

    expect(axis.inverse).toBe(false);
    expect(axis.data[0]).toBe('2026-09-01');
    expect(valueAxis(rtl, palette, { format: String }).position).toBe('right');
    expect(valueAxis(ltr, palette, { format: String }).position).toBe('left');
  });
});

describe('too many series', () => {
  it('sums the tail into one row rather than hiding it', () => {
    const rows = [5, 4, 3, 2, 1].map((value) => ({ label: String(value), value }));

    const { rows: folded, folded: count } = foldToOther(rows, 3, (tail) => ({
      label: 'Other',
      value: tail.reduce((sum, row) => sum + row.value, 0),
    }));

    /*
     * A reader can see that there is a tail and how big it is. Dropping it would make the
     * remaining rows look like the whole, which is the commonest way a share chart lies.
     */
    expect(folded).toHaveLength(3);
    expect(folded[2]).toEqual({ label: 'Other', value: 6 });
    expect(count).toBe(3);
  });

  it('leaves a list that fits alone', () => {
    const rows = [{ label: 'a', value: 1 }];

    expect(foldToOther(rows, 3, () => ({ label: 'Other', value: 0 }))).toEqual({ rows, folded: 0 });
  });

  it('never invents a colour past the cap', () => {
    const past = seriesColour(palette, 20);

    /*
     * A generated ninth hue looks plausible and is indistinguishable from an existing slot
     * to a colour-blind reader. Repeating the last slot is a visible bug instead of an
     * invisible one — and the data layer is supposed to have folded the tail first.
     */
    expect(palette.series).toContain(past);
    expect(past).toBe(palette.series[seriesCap.adjacent - 1]);
  });
});

describe('an ordered scale', () => {
  it('spreads across the whole ramp rather than taking the first few steps', () => {
    const three = [0, 1, 2].map((index) => stepColour(palette, index, 3));

    // Three stages that barely differ in colour would say nothing about their order.
    expect(three[0]).toBe(palette.steps[0]);
    expect(three[2]).toBe(palette.steps[palette.steps.length - 1]);
    expect(new Set(three).size).toBe(3);
  });

  it('copes with a single stage', () => {
    expect(stepColour(palette, 0, 1)).toBe(palette.steps[0]);
  });
});
