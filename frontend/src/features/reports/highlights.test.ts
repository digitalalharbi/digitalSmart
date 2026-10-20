import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { campaignCards, insights, recommendations } from './highlights';
import type { RenderedReport, RenderedSection } from './types';

function value(
  metric: string,
  amount: number | null,
  over: Partial<MetricValue> = {},
): MetricValue {
  return {
    metric,
    label: metric,
    value: amount,
    format: 'count',
    precision: 2,
    is_minor_units: false,
    currency: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    unavailable_reason: null,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
    ...over,
  };
}

const set = (values: Record<string, MetricValue>) => ({
  values,
  source: 'synced',
  currency: null,
  row_count: 1,
  is_empty: false,
});

function section(over: Partial<RenderedSection>): RenderedSection {
  return {
    kind: 'scorecard',
    title: 't',
    metrics: [],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    caveats: [],
    currency: null,
    totals: null,
    series: [],
    rows: [],
    ...over,
  };
}

function report(sections: RenderedSection[]): RenderedReport {
  return { sections } as unknown as RenderedReport;
}

const rows = (entries: Array<[string, number | null, number | null]>) =>
  entries.map(([label, spend, roas]) => ({
    key: label,
    label,
    status: null,
    metrics: set({ spend: value('spend', spend), roas: value('roas', roas) }),
  }));

describe('insights', () => {
  it('turns a clear improvement into an improved card and a bad drop into attention', () => {
    const cards = insights(
      report([
        section({
          totals: set({
            roas: value('roas', 4.2, { change_ratio: 0.21, change_is_good: true }),
            cpa: value('cpa', 30, {
              change_ratio: 0.18,
              change_is_good: false,
              lower_is_better: true,
            }),
          }),
        }),
      ]),
    );

    expect(cards.map((card) => [card.key, card.kind])).toEqual([
      ['cpa', 'attention'],
      ['roas', 'improved'],
    ]);
  });

  it('ignores noise and changes the server did not offer', () => {
    const cards = insights(
      report([
        section({
          totals: set({
            spend: value('spend', 10, { change_ratio: 0.02, change_is_good: true }),
            clicks: value('clicks', 10, { change_ratio: null, change_is_good: null }),
          }),
        }),
      ]),
    );

    expect(cards).toEqual([]);
  });

  it('names a best performer only from three or more campaigns', () => {
    const two = report([
      section({
        kind: 'breakdown',
        dimension: 'campaign',
        metrics: ['spend', 'roas'],
        rows: rows([
          ['A', 9, 2],
          ['B', 5, 1],
        ]),
      }),
    ]);
    const three = report([
      section({
        kind: 'breakdown',
        dimension: 'campaign',
        metrics: ['spend', 'roas'],
        rows: rows([
          ['A', 9, 2],
          ['B', 5, 1],
          ['C', 2, 1],
        ]),
      }),
    ]);

    expect(insights(two).some((card) => card.kind === 'best')).toBe(false);
    expect(insights(three).find((card) => card.kind === 'best')?.subject).toBe('A');
  });
});

describe('campaignCards', () => {
  const base = (
    entries: Array<[string, number | null, number | null]>,
    accountRoas: number | null,
  ) =>
    report([
      section({ totals: set({ roas: value('roas', accountRoas) }) }),
      section({
        kind: 'breakdown',
        dimension: 'campaign',
        metrics: ['spend', 'roas'],
        rows: rows(entries),
      }),
    ]);

  it('ranks the three the server put first, and flags those clearly below the report’s own figure', () => {
    const { top, attention } = campaignCards(
      base(
        [
          ['A', 90, 5],
          ['B', 80, 4],
          ['C', 70, 4],
          ['D', 10, 1],
          ['E', 9, 3.9],
        ],
        4,
      ),
    );

    expect(top.map((card) => card.label)).toEqual(['A', 'B', 'C']);
    expect(attention.map((card) => card.label)).toEqual(['D']);
  });

  it('flags nothing when the report has no figure to compare against', () => {
    const { attention } = campaignCards(
      base(
        [
          ['A', 9, 5],
          ['B', 8, 4],
          ['C', 7, 4],
          ['D', 1, 1],
        ],
        null,
      ),
    );

    expect(attention).toEqual([]);
  });

  it('treats a missing figure as missing, never as the worst', () => {
    const { attention } = campaignCards(
      base(
        [
          ['A', 9, 5],
          ['B', 8, 4],
          ['C', 7, 4],
          ['D', 1, null],
        ],
        4,
      ),
    );

    expect(attention).toEqual([]);
  });
});

describe('recommendations', () => {
  it('points at a platform clearly ahead of the report’s own return, with both figures', () => {
    const found = recommendations(
      report([
        section({ totals: set({ roas: value('roas', 3.6) }) }),
        section({
          kind: 'breakdown',
          dimension: 'provider',
          metrics: ['spend', 'roas'],
          rows: rows([
            ['Meta', 9, 5.2],
            ['Google', 8, 2.5],
          ]),
        }),
      ]),
    );

    expect(found).toHaveLength(1);
    expect(found[0]).toMatchObject({ key: 'allocate', subject: 'Meta' });
    expect(found[0]?.current.value).toBe(5.2);
    expect(found[0]?.against?.value).toBe(3.6);
  });

  it('says nothing when the lead is modest or only one platform reported', () => {
    const modest = report([
      section({ totals: set({ roas: value('roas', 3.6) }) }),
      section({
        kind: 'breakdown',
        dimension: 'provider',
        metrics: ['roas'],
        rows: rows([
          ['Meta', 9, 4],
          ['Google', 8, 3],
        ]),
      }),
    ]);
    const lone = report([
      section({ totals: set({ roas: value('roas', 3.6) }) }),
      section({
        kind: 'breakdown',
        dimension: 'provider',
        metrics: ['roas'],
        rows: rows([
          ['Meta', 9, 9],
          ['Google', 8, null],
        ]),
      }),
    ]);

    expect(recommendations(modest)).toEqual([]);
    expect(recommendations(lone)).toEqual([]);
  });

  it('suggests refreshing creative only when click-through fell by a noticeable margin', () => {
    const fell = report([
      section({
        totals: set({ ctr: value('ctr', 1.2, { change_ratio: -0.19, change_is_good: false }) }),
      }),
    ]);
    const slight = report([
      section({
        totals: set({ ctr: value('ctr', 1.2, { change_ratio: -0.06, change_is_good: false }) }),
      }),
    ]);

    expect(recommendations(fell).map((r) => r.key)).toEqual(['creative']);
    expect(recommendations(slight)).toEqual([]);
  });
});
