import { describe, expect, it } from 'vitest';
import type { EChartsCoreOption } from 'echarts/core';
import { seriesDashes, seriesDecals } from './theme';
import { textureOption, withTexture } from './texture';

/*
 * The backup channel for readers colour cannot reach: full colour blindness, a grey print, a
 * forced-colours display. What is asserted here is the shape of the option — that the channel is
 * on, that it reaches lines as well as fills, and that turning it off really turns it off. Whether
 * two patterns look different at 24 pixels is a design judgement and is not pretended to be
 * measured, unlike the colour separations in `palette.gates.test.ts`.
 */

function option(series: unknown[]): EChartsCoreOption {
  return { series };
}

function line(name: string, extra: Record<string, unknown> = {}): Record<string, unknown> {
  return { name, type: 'line', data: [1, 2, 3], ...extra };
}

describe('the pattern channel', () => {
  it('turns ECharts’ own decal channel on', () => {
    const result = textureOption() as {
      aria: { enabled: boolean; decal: { show: boolean; decals: unknown[] } };
    };

    expect(result.aria.enabled).toBe(true);
    expect(result.aria.decal.show).toBe(true);
    expect(result.aria.decal.decals).toHaveLength(seriesDecals.length);
  });

  /*
   * The non-obvious one. Enabling `aria` also makes ECharts write its own sentence describing the
   * chart onto the element — a second, machine-written claim about figures this product is careful
   * about, competing with the description every chart here is required to supply.
   */
  it('does not let ECharts write its own description of the data', () => {
    const result = textureOption() as { aria: { label: { enabled: boolean } } };

    expect(result.aria.label.enabled).toBe(false);
  });

  it('dashes each line series by its slot, so pattern and colour name the same series', () => {
    const result = withTexture(option([line('Spend'), line('Revenue')]), true) as {
      series: { lineStyle: { type: number[] }; showSymbol: boolean }[];
    };

    expect(result.series[0]?.lineStyle.type).toEqual([...seriesDashes[0]]);
    expect(result.series[1]?.lineStyle.type).toEqual([...seriesDashes[1]]);
    /* A dash is hard to follow where lines cross; the markers say which line is which there. */
    expect(result.series[0]?.showSymbol).toBe(true);
  });

  /*
   * A dashed line already means "this is not data" everywhere in this product — a target, a limit,
   * a comparison. Overwriting that would make a threshold look like a measurement.
   */
  it('leaves a line that is already dashed alone', () => {
    const result = withTexture(
      option([line('Target', { lineStyle: { type: 'dashed', width: 1 } })]),
      true,
    ) as { series: { lineStyle: { type: string } }[] };

    expect(result.series[0]?.lineStyle.type).toBe('dashed');
  });

  it('does not touch a bar series, which ECharts decals itself', () => {
    const bar = { name: 'Orders', type: 'bar', data: [3] };

    const result = withTexture(option([bar]), true) as { series: unknown[] };

    expect(result.series[0]).toEqual(bar);
  });

  /*
   * Off has to mean off. The same option object is handed to a second chart and `setOption` is
   * called with `notMerge`, so an absent key would leave whatever the instance already had — which
   * would make the preference look like it only ever turns on.
   */
  it('writes the disabling values rather than omitting the keys', () => {
    const result = withTexture(option([line('Spend')]), false) as {
      aria: { enabled: boolean };
      series: { lineStyle: { type: string } }[];
    };

    expect(result.aria.enabled).toBe(false);
    expect(result.series[0]?.lineStyle.type).toBe('solid');
  });

  it('survives an option with no series at all', () => {
    expect(() => withTexture({}, true)).not.toThrow();
  });
});

describe('the pattern table', () => {
  /*
   * Pairwise distinct as definitions, which catches a copy-paste. Eight angles of one hatch would
   * pass a weaker check and fail a reader: four patterns and four near-duplicates.
   */
  it('has one distinct definition per categorical slot', () => {
    const written = seriesDecals.map((decal) => JSON.stringify(decal));

    expect(new Set(written).size).toBe(seriesDecals.length);
    expect(seriesDecals).toHaveLength(8);
  });

  it('has one distinct dash per slot, and none of them solid', () => {
    const written = seriesDashes.map((dash) => dash.join(','));

    expect(new Set(written).size).toBe(seriesDashes.length);
    expect(seriesDashes).toHaveLength(8);

    /*
     * Slot 1 is dashed too, deliberately. A solid first line beside seven dashed ones reads as the
     * real measurement among projections, which is what dashes mean elsewhere in this product.
     */
    for (const dash of seriesDashes) {
      expect(dash.length).toBeGreaterThan(1);
    }
  });

  it('covers every slot the palette hands out', () => {
    expect(seriesDecals).toHaveLength(seriesDashes.length);
  });
});
