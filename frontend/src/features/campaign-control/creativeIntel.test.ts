import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { creativeItems, creativeViews, formatMix, MIN_IMPRESSIONS } from './creativeIntel';
import type { Ad } from './hierarchyData';

function value(metric: string, amount: number | null): MetricValue {
  return {
    metric,
    label: metric,
    value: amount,
    format: 'count',
    precision: 0,
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
  };
}

function ad(id: string, change: number | null, ctr: number | null, format = 'image'): Ad {
  return {
    id,
    name: `Ad ${id}`,
    status: 'active',
    creative: { id: `c-${id}`, name: `Creative ${id}`, format, title: null, thumbnail_url: null },
    spend_minor: 1000,
    ctr_now: ctr,
    ctr_change: change,
    frequency: 2,
  };
}

const figures = (impressions: number, conversions = 0, spend = 100) => ({
  impressions: value('impressions', impressions),
  conversions: value('conversions', conversions),
  spend: value('spend', spend),
  ctr: value('ctr', 2),
});

describe('creativeItems', () => {
  it('judges a creative only once it has been seen enough, and says so otherwise', () => {
    const items = creativeItems([{ name: 'G', ads: [ad('a', -0.5, 1), ad('b', -0.5, 1)] }], {
      a: figures(MIN_IMPRESSIONS - 1),
      b: figures(MIN_IMPRESSIONS),
    });

    expect(items.map((item) => item.state)).toEqual(['not_enough_data', 'tiring']);
  });

  it('calls a creative with no figures at all not enough data, not weak', () => {
    const [item] = creativeItems([{ name: 'G', ads: [ad('a', null, null)] }], {});

    expect(item?.state).toBe('not_enough_data');
  });

  it('reads rising and tiring from the click-through change the hierarchy carries', () => {
    const items = creativeItems(
      [{ name: 'G', ads: [ad('up', 0.3, 3), ad('flat', 0.05, 2), ad('down', -0.3, 3)] }],
      { up: figures(5000), flat: figures(5000), down: figures(5000) },
    );

    expect(items.map((item) => item.state)).toEqual(['rising', 'steady', 'tiring']);
  });

  it('asks for replacement only of a tiring creative that is also behind its siblings', () => {
    const items = creativeItems(
      [{ name: 'G', ads: [ad('a', -0.4, 1.0), ad('b', -0.4, 4.0), ad('c', 0, 3.0)] }],
      { a: figures(5000), b: figures(5000), c: figures(5000) },
    );

    // Median click-through is 3.0: A is tiring and below it; B is tiring and well above it.
    expect(items.map((item) => item.state)).toEqual(['needs_replacing', 'tiring', 'steady']);
  });
});

describe('creativeViews', () => {
  it('leaves an unjudged creative out of every list', () => {
    const items = creativeItems([{ name: 'G', ads: [ad('a', 0.5, 5)] }], { a: figures(10) });
    const views = creativeViews(items);

    expect(Object.values(views).every((list) => list.length === 0)).toBe(true);
  });

  it('orders the top by results first, then click-through, so it never depends on luck', () => {
    const items = creativeItems([{ name: 'G', ads: [ad('few', 0, 2), ad('many', 0, 1)] }], {
      few: figures(5000, 1),
      many: figures(5000, 9),
    });

    expect(creativeViews(items).top.map((item) => item.id)).toEqual(['many', 'few']);
  });

  it('puts a replace-me creative under tiring as well', () => {
    const items = creativeItems(
      [{ name: 'G', ads: [ad('a', -0.4, 1.0), ad('b', 0, 4.0), ad('c', 0, 3.0)] }],
      { a: figures(5000), b: figures(5000), c: figures(5000) },
    );
    const views = creativeViews(items);

    expect(views.replace.map((item) => item.id)).toEqual(['a']);
    expect(views.tiring.map((item) => item.id)).toEqual(['a']);
  });
});

describe('formatMix', () => {
  it('sums what adds up by format and leaves an ad with no format out', () => {
    const items = creativeItems(
      [
        {
          name: 'G',
          ads: [ad('a', 0, 2, 'video'), ad('b', 0, 2, 'image'), ad('c', 0, 2, 'video')],
        },
      ],
      { a: figures(5000, 2, 300), b: figures(5000, 1, 100), c: figures(5000, 3, 200) },
    );
    const noFormat = creativeItems([{ name: 'G', ads: [{ ...ad('d', 0, 2), creative: null }] }], {
      d: figures(5000, 1, 999),
    });

    expect(formatMix([...items, ...noFormat])).toEqual([
      { format: 'video', spend: 500, results: 5 },
      { format: 'image', spend: 100, results: 1 },
    ]);
  });
});
