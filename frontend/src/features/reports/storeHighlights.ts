import type { StoreDetail } from './types';

/**
 * What a store section says, from its own figures and nothing else.
 *
 * Every card reads two numbers the server sent, and none names a cause. A change needs the comparison period, so
 * without one there is no "improved" or "declined" — only what the period holds.
 */

/** A change smaller than this is not worth a card. */
export const NOTICEABLE = 0.05;
/** A refund rate that moved by at least this many points is worth saying. */
export const REFUND_POINTS = 1;
/** A store must out-earn the stores' own average order value by this factor to be pointed at. */
export const AOV_LEADING = 1.2;
/** Fewer orders than this and an average order value is noise. */
export const MIN_ORDERS = 5;
/** Fewer days with orders than this and one of them is not "strongest". */
export const MIN_DAYS = 3;

export type StoreInsight = {
  key: string;
  kind: 'best' | 'improved' | 'declined' | 'attention';
  /** Translation key under `reports.commerce.insight`. */
  text: string;
  values: Record<string, string | number>;
};

export type StoreAdvice = {
  key: string;
  text: string;
  evidence: string;
  values: Record<string, string | number>;
};

/** The change between two figures as a ratio, or null where the earlier one is zero — there is no percentage from nothing. */
export function change(now: number, before: number): number | null {
  return before > 0 ? (now - before) / before : null;
}

/** The day with the most revenue, or null with too few days to call one the strongest. */
export function strongestDay(detail: StoreDetail): { date: string; revenue: number } | null {
  if (detail.daily.length < MIN_DAYS) {
    return null;
  }

  const best = detail.daily.reduce((top, day) => (day.net_revenue > top.net_revenue ? day : top));

  return best.net_revenue > 0 ? { date: best.date, revenue: best.net_revenue } : null;
}

/** Orders now against the earlier period, or null with nothing to compare against. */
export function ordersChange(detail: StoreDetail, orders: number): number | null {
  return detail.previous === null ? null : change(orders, detail.previous.orders);
}

/** How many points the refund rate moved, or null where either period has no rate. */
export function refundPoints(detail: StoreDetail): number | null {
  const { rate, previous_rate: before } = detail.refunds;

  return rate === null || before === null ? null : Math.round((rate - before) * 10) / 10;
}

export function storeInsights(detail: StoreDetail, orders: number): StoreInsight[] {
  const found: StoreInsight[] = [];
  const day = strongestDay(detail);

  if (day !== null) {
    found.push({ key: 'day', kind: 'best', text: 'strongestDay', values: { date: day.date } });
  }

  const moved = ordersChange(detail, orders);

  if (moved !== null && Math.abs(moved) >= NOTICEABLE) {
    found.push({
      key: 'orders',
      kind: moved > 0 ? 'improved' : 'declined',
      text: moved > 0 ? 'ordersUp' : 'ordersDown',
      values: { percent: Math.round(Math.abs(moved) * 100) },
    });
  }

  const points = refundPoints(detail);

  if (points !== null && points >= REFUND_POINTS) {
    found.push({
      key: 'refunds',
      kind: 'attention',
      text: 'refundsUp',
      values: { points },
    });
  }

  const top = detail.stores[0];

  if (top !== undefined && top.share !== null && top.share >= 50) {
    found.push({
      key: 'store',
      kind: 'best',
      text: 'topStore',
      values: { store: top.name, share: Math.round(top.share) },
    });
  }

  return found;
}

export function storeAdvice(detail: StoreDetail): StoreAdvice[] {
  const advice: StoreAdvice[] = [];
  const { rate, previous_rate: before } = detail.refunds;
  const points = refundPoints(detail);

  if (points !== null && points >= REFUND_POINTS && rate !== null && before !== null) {
    advice.push({
      key: 'refunds',
      text: 'reviewRefunds',
      evidence: 'reviewRefundsEvidence',
      values: { from: before, to: rate },
    });
  }

  const orders = detail.stores.reduce((sum, row) => sum + row.orders, 0);
  const revenue = detail.stores.reduce((sum, row) => sum + row.net_revenue, 0);
  const average = orders > 0 ? revenue / orders : null;

  if (average !== null && average > 0) {
    const lead = detail.stores
      .filter(
        (row) => row.orders >= MIN_ORDERS && row.aov !== null && row.aov >= average * AOV_LEADING,
      )
      .sort((a, b) => (b.aov ?? 0) - (a.aov ?? 0))[0];

    if (lead !== undefined && lead.aov !== null) {
      advice.push({
        key: 'store',
        text: 'focusStore',
        evidence: 'focusStoreEvidence',
        values: { store: lead.name, percent: Math.round((lead.aov / average - 1) * 100) },
      });
    }
  }

  return advice;
}
