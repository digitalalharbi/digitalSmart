import { describe, expect, it } from 'vitest';
import { breakdownOption, trendMetricsFor, trendOption } from './charts';
import type {
  AnalyticsPayload,
  BreakdownRowPayload,
  MetricDefinition,
  SeriesPointPayload,
} from './types';

function metricValue(value: number | null, minor = false) {
  return {
    metric: 'spend',
    label: 'Spend',
    value,
    format: minor ? ('currency' as const) : ('count' as const),
    precision: minor ? 2 : 0,
    is_minor_units: minor,
    currency: minor ? 'SAR' : null,
    source: 'synced' as const,
    source_label: 'Synced',
    requires_label: true,
    unavailable_reason: null,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
  };
}

function point(date: string, spend: number | null): SeriesPointPayload {
  return {
    date,
    source: 'synced',
    currency: 'SAR',
    row_count: spend === null ? 0 : 1,
    values: { spend: metricValue(spend, true), roas: metricValue(spend === null ? null : 2) },
  };
}

const payload: AnalyticsPayload = {
  range: { start: '2026-09-01', end: '2026-09-04', days: 4 },
  compare_to: null,
  metrics: ['spend', 'roas'],
  source: 'synced',
  source_label: 'Synced',
  requires_label: true,
  is_empty: false,
  summary: {
    source: 'synced',
    currency: 'SAR',
    row_count: 3,
    values: { spend: metricValue(30_000, true) },
  },
  series: [
    point('2026-09-01', 10_000),
    // Delivery stopped on the second day.
    point('2026-09-02', null),
    point('2026-09-03', 20_000),
    point('2026-09-04', 15_000),
  ],
  breakdown: [],
  dimension: null,
};

const definitions = new Map<string, MetricDefinition>([
  [
    'spend',
    {
      value: 'spend',
      label: 'Spend',
      format: 'currency',
      precision: 2,
      is_derived: false,
      is_monetary: true,
      additive_across_entities: true,
      lower_is_better: false,
      depends_on: [],
    },
  ],
]);

describe('trendOption', () => {
  it('leaves a gap where delivery stopped rather than bridging it', () => {
    const option = trendOption(payload, ['spend'], definitions, { locale: 'en', isRtl: false });
    const series = (option.series as { data: (number | null)[]; connectNulls?: boolean }[])[0];

    /*
     * A line drawn through a gap says delivery continued at some level, when in
     * fact it stopped — the most misleading thing a performance chart can do.
     */
    expect(series?.connectNulls).toBe(false);
    expect(series?.data[1]).toBeNull();
  });

  it('converts minor units once for the canvas', () => {
    const option = trendOption(payload, ['spend'], definitions, { locale: 'en', isRtl: false });
    const series = (option.series as { data: (number | null)[] }[])[0];

    // 10,000 minor units plots as 100.
    expect(series?.data[0]).toBe(100);
  });

  it('keeps time left to right in Arabic and moves only the value axis', () => {
    const ltr = trendOption(payload, ['spend'], definitions, { locale: 'en', isRtl: false });
    const rtl = trendOption(payload, ['spend'], definitions, { locale: 'ar', isRtl: true });

    const ltrAxis = ltr.xAxis as { data: string[]; inverse: boolean };
    const rtlAxis = rtl.xAxis as { data: string[]; inverse: boolean };

    /*
     * Time stays chronological left to right in Arabic: a timeline that reversed with the page would put last week on
     * the wrong side of this week for anybody reading a chart from a screenshot. What localises is the value axis
     * (which moves to the reading side) and the labels and tooltips.
     */
    expect(ltrAxis.data[0]).toBe('2026-09-01');
    expect(rtlAxis.data[0]).toBe('2026-09-01');
    expect(ltrAxis.inverse).toBe(false);
    expect(rtlAxis.inverse).toBe(false);
    expect((ltr.yAxis as { position: string }).position).toBe('left');
    expect((rtl.yAxis as { position: string }).position).toBe('right');
  });

  it('never puts two value scales on one plot', () => {
    const option = trendOption(payload, ['ctr', 'spend'], definitions, {
      locale: 'en',
      isRtl: false,
    });

    const grids = option.grid as unknown[];
    const yAxes = option.yAxis as { gridIndex?: number }[];

    /*
     * The rule this test exists for: two measures of different scale get two plots, never
     * two scales on one plot. Where a second axis lines up with the first is an arbitrary
     * choice, so the lines appear to cross and diverge at moments that mean nothing — and
     * "conversions track spend" is exactly the sort of claim a budget gets approved on.
     */
    expect(grids).toHaveLength(2);
    expect(new Set(yAxes.map((axis) => axis.gridIndex ?? 0)).size).toBe(yAxes.length);

    // And one crosshair runs through both, so the comparison is still a single glance.
    expect(option.axisPointer).toEqual({ link: [{ xAxisIndex: 'all' }] });
  });

  it('plots spend alongside whatever else was chosen', () => {
    // Every other figure means something different depending on what was spent
    // to get it, so spend is the constant second line.
    expect(trendMetricsFor('ctr')).toEqual(['ctr', 'spend']);
    expect(trendMetricsFor('spend')).toEqual(['spend', 'roas']);
  });
});

describe('breakdownOption', () => {
  const rows: BreakdownRowPayload[] = [
    {
      dimension: 'campaign',
      key: 'a',
      label: 'Biggest',
      external_id: '1',
      status: 'active',
      parent_key: null,
      source: 'synced',
      currency: 'SAR',
      row_count: 1,
      values: { spend: metricValue(50_000, true) },
    },
    {
      dimension: 'campaign',
      key: 'b',
      label: 'Smallest',
      external_id: '2',
      status: 'paused',
      parent_key: null,
      source: 'synced',
      currency: 'SAR',
      row_count: 1,
      values: { spend: metricValue(10_000, true) },
    },
  ];

  it('puts the largest bar at the top', () => {
    const option = breakdownOption(rows, 'spend', definitions, { locale: 'en', isRtl: false });

    // ECharts stacks a category axis bottom-up, so the order is reversed to put
    // the biggest value where a reader looks first.
    expect((option.yAxis as { data: string[] }).data).toEqual(['Smallest', 'Biggest']);
  });

  it('moves the category labels to the right in Arabic', () => {
    const option = breakdownOption(rows, 'spend', definitions, { locale: 'ar', isRtl: true });

    expect((option.yAxis as { position: string }).position).toBe('right');
    expect((option.xAxis as { inverse: boolean }).inverse).toBe(true);
  });
});
