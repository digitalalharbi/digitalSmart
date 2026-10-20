import { describe, expect, it } from 'vitest';
import { change, refundPoints, storeAdvice, storeInsights, strongestDay } from './storeHighlights';
import type { StoreDetail } from './types';

function detail(overrides: Partial<StoreDetail> = {}): StoreDetail {
  return {
    currency: 'SAR',
    daily: [
      { date: '2026-10-01', orders: 3, net_revenue: 30_000 },
      { date: '2026-10-02', orders: 5, net_revenue: 90_000 },
      { date: '2026-10-04', orders: 2, net_revenue: 10_000 },
    ],
    stores: [],
    products: [],
    channels: [],
    attribution_coverage: null,
    customers: null,
    refunds: { amount: 1_000, orders: 1, rate: 8, previous_rate: 4 },
    previous: { orders: 8, net_revenue: 100_000, aov: 12_500 },
    ...overrides,
  };
}

describe('change', () => {
  it('has no percentage from nothing', () => {
    expect(change(5, 0)).toBeNull();
    expect(change(15, 10)).toBe(0.5);
  });
});

describe('strongestDay', () => {
  it('needs enough days to call one the strongest', () => {
    expect(strongestDay(detail())?.date).toBe('2026-10-02');
    expect(strongestDay(detail({ daily: detail().daily.slice(0, 2) }))).toBeNull();
  });
});

describe('refundPoints', () => {
  it('moves only between two real rates', () => {
    expect(refundPoints(detail())).toBe(4);
    expect(
      refundPoints(detail({ refunds: { amount: 0, orders: 0, rate: 5, previous_rate: null } })),
    ).toBeNull();
    expect(
      refundPoints(detail({ refunds: { amount: 0, orders: 0, rate: null, previous_rate: 5 } })),
    ).toBeNull();
  });
});

describe('storeInsights', () => {
  it('reads orders against the earlier period only when there is one', () => {
    const keys = (value: StoreDetail, orders: number): string[] =>
      storeInsights(value, orders).map((insight) => insight.text);

    expect(keys(detail(), 4)).toContain('ordersDown');
    expect(keys(detail(), 12)).toContain('ordersUp');
    expect(keys(detail({ previous: null }), 12)).not.toContain('ordersUp');
  });

  it('says nothing about a small change', () => {
    expect(
      storeInsights(detail({ previous: { orders: 100, net_revenue: 1, aov: 1 } }), 102).some(
        (i) => i.key === 'orders',
      ),
    ).toBe(false);
  });

  it('names the refund rise only with two rates, and the top store only with a majority', () => {
    expect(storeInsights(detail(), 8).map((i) => i.key)).toContain('refunds');
    expect(
      storeInsights(
        detail({ refunds: { amount: 0, orders: 0, rate: 8, previous_rate: null } }),
        8,
      ).map((i) => i.key),
    ).not.toContain('refunds');

    const stores = [
      { name: 'A', orders: 9, net_revenue: 90_000, refunded: 0, aov: 10_000, share: 60 },
      { name: 'B', orders: 6, net_revenue: 60_000, refunded: 0, aov: 10_000, share: 40 },
    ];

    expect(storeInsights(detail({ stores }), 8).find((i) => i.key === 'store')?.values).toEqual({
      store: 'A',
      share: 60,
    });
  });
});

describe('storeAdvice', () => {
  it('cites the two refund rates it rests on', () => {
    expect(storeAdvice(detail())[0]?.values).toEqual({ from: 4, to: 8 });
  });

  it('points at a store only on a clearly higher average order and enough orders', () => {
    const stores = [
      { name: 'A', orders: 10, net_revenue: 200_000, refunded: 0, aov: 20_000, share: 60 },
      { name: 'B', orders: 10, net_revenue: 100_000, refunded: 0, aov: 10_000, share: 40 },
      { name: 'C', orders: 2, net_revenue: 60_000, refunded: 0, aov: 30_000, share: 0 },
    ];
    const found = storeAdvice(
      detail({ refunds: { amount: 0, orders: 0, rate: null, previous_rate: null }, stores }),
    ).find((a) => a.key === 'store');

    // C has the biggest average and too few orders to count; A leads on a real sample.
    expect(found?.values['store']).toBe('A');
  });

  it('never advises on budget', () => {
    expect(JSON.stringify(storeAdvice(detail())).toLowerCase()).not.toContain('budget');
  });
});
