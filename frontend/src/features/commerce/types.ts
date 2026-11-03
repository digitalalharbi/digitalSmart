import type { MetricSourceValue } from '@/shared/metrics';

/**
 * The commerce analytics payload, as the server publishes it.
 *
 * Every revenue figure arrives in minor units with its currency, and every total arrives with two
 * labels: who is claiming it, and how complete the rows behind it are.
 */

/**
 * Who says this revenue figure is true.
 *
 * Never merged. A Salla figure counts invoices; a GA4 figure counts what a browser reported before the
 * payment failed; an ad platform reports what it believes it caused. There is no total across them.
 */
export type RevenueSourceValue =
  'platform_reported' | 'google_analytics' | 'salla' | 'zid' | 'crm' | 'manual' | 'attributed';

/** What an order carried that links it to marketing. */
export type AttributionEvidenceValue =
  'click_identifier' | 'utm_parameters' | 'partial_utm' | 'coupon_code' | 'referrer' | 'none';

export type StoreRevenueSummary = {
  revenue_source: RevenueSourceValue;
  revenue_source_label: string;
  /** True only for a store or a CRM. Everything else is a measurement of, or a claim about, money. */
  is_invoiced: boolean;
  /** True for attributed revenue, which must never be shown as actual. */
  is_modelled: boolean;
  source: MetricSourceValue;
  source_label: string;
  currency: string;
  orders: number;
  revenue_minor: number;
  refunded_minor: number;
  /** The figure a client should be shown: sales less what came back. */
  net_revenue_minor: number;
  /** Null when nothing counted. A denominator of zero has no average. */
  average_order_value_minor: number | null;
  units: number;
  customers: number;
  /** Real orders that are not yet money — mostly cash on delivery. */
  pending_orders: number;
  pending_revenue_minor: number;
  attributed_orders: number;
  attributed_revenue_minor: number;
  /** What share of the counted money can be traced to marketing at all, or null. */
  attribution_coverage: number | null;
};

export type RevenueBreakdownRow = {
  label: string;
  orders: number;
  revenue_minor: number;
  refunded_minor: number;
  net_revenue_minor: number;
  units: number;
  /** The **weakest** evidence anything in this row rests on. Null for the unattributed row. */
  evidence: AttributionEvidenceValue | null;
  evidence_label: string | null;
  confidence: number | null;
  is_unattributed: boolean;
  external_id: string | null;
};

export type DailyRevenuePoint = {
  date: string;
  orders: number;
  net_revenue_minor: number;
  refunded_minor: number;
  /** The period cuts through this week or month, so it holds fewer days than its neighbours. */
  is_partial?: boolean;
};

export type TrendGrain = 'day' | 'week' | 'month';

/** Revenue at one grain; each point is dated by the first day of its bucket in the store's own calendar. */
export type TrendSeries = {
  grain: TrendGrain;
  /** Weeks begin on Monday. */
  week_starts_on: 'monday';
  points: DailyRevenuePoint[];
};

/**
 * The same number of days immediately before the period. The changes are null — never zero — when there is nothing to compare
 * with, and `unavailable_reason` says whether the store sold nothing then or was not being read yet.
 */
export type StoreComparison = {
  previous: {
    from: string;
    until: string;
    orders: number;
    net_revenue_minor: number;
    average_order_value_minor: number | null;
  };
  change: {
    orders_pct: number | null;
    net_revenue_pct: number | null;
    average_order_value_pct: number | null;
  };
  unavailable_reason: 'nothing_sold_before' | 'before_the_data' | null;
};

/**
 * How much of a period came from customers new to the store.
 *
 * Three buckets, and the third is why this is publishable. `unknown` counts customers whose first recorded order
 * sits at the edge of what was ever synced — they may have been buying for years, and calling them new would make
 * every freshly connected store look like a triumph.
 *
 * `new_share` is **null** rather than computed whenever that bucket is a fifth or more of the period, and
 * `unavailable_reason` says which of the two reasons applies: nothing was sold, or too much cannot be classified.
 * Only one of those gets better on its own.
 */
export type CustomerMix = {
  new_customers: number;
  returning_customers: number;
  unknown_customers: number;
  new_revenue_minor: number;
  returning_revenue_minor: number;
  unknown_revenue_minor: number;
  new_share: number | null;
  unavailable_reason: string | null;
  buckets: { recency: 'new' | 'returning' | 'unknown'; label: string }[];
};

/**
 * What the totals were made of. A component's `amount_minor` is **null** when no counted order reported it — not zero — and
 * `orders_reporting` against `orders_counted` says how much of the period it covers. `reconciles` is null when no order reported
 * enough to be checked.
 */
export type RevenueLedger = {
  currency: string;
  orders: number;
  total_minor: number;
  refunded_minor: number;
  net_minor: number;
  components: {
    key: 'subtotal' | 'discount' | 'shipping' | 'tax';
    amount_minor: number | null;
    orders_reporting: number;
    orders_counted: number;
  }[];
  reconciliation: {
    orders_checked: number;
    orders_off: number;
    unexplained_minor: number | null;
    reconciles: boolean | null;
  };
};

export type StoreAnalytics = {
  from: string;
  until: string;
  summary: StoreRevenueSummary;
  by_channel: RevenueBreakdownRow[];
  by_campaign: RevenueBreakdownRow[];
  by_product: RevenueBreakdownRow[];
  daily: DailyRevenuePoint[];
  customer_mix: CustomerMix;
  /** Absent where the audience was not given it (the client portal's revenue module). */
  ledger?: RevenueLedger;
  series?: TrendSeries;
  comparison?: StoreComparison;
};

export type CommerceQuery = {
  revenue_source: RevenueSourceValue;
  from?: string;
  until?: string;
  client_id?: string | null;
  limit?: number;
  grain?: TrendGrain;
  compare?: 'previous';
};

/**
 * One store this workspace reads.
 *
 * `last_synced_at` and `orders_synced_through` are different facts and both travel: the first says when
 * somebody last asked the platform, the second how far through its orders the answer got — and it moves only
 * when orders landed. A store that is failing has the first and not the second, which is the state a single
 * "last updated" column hides.
 */
export type Store = {
  id: string;
  client_id: string;
  client_name: string;
  provider: string;
  provider_label: string;
  external_id: string;
  name: string;
  domain: string | null;
  /** Every amount read from this store is in it. */
  currency: string;
  timezone: string | null;
  is_active: boolean;
  last_synced_at: string | null;
  orders_synced_through: string | null;
  /** Whether anything can go and read it: a provider with no sync adapter cannot be. */
  is_readable: boolean;
};

/** One merchant a connection can read, and whether this workspace already reads it. */
export type ConnectableStore = {
  provider: string;
  provider_label: string;
  external_id: string;
  name: string;
  currency: string;
  domain: string | null;
  timezone: string | null;
  is_registered: boolean;
  registered_to_client_name: string | null;
};

/** What is sent to connect one. Everything else is read back from the platform. */
export type StoreDraft = { provider: string; external_id: string; client_id: string };

export type StoreReadStatus = 'succeeded' | 'partial' | 'failed' | 'skipped';

/** What a read brought back. Four statuses, because they are four different facts. */
export type StoreReadResult = {
  status: StoreReadStatus;
  orders_written: number;
  products_written: number;
  customers_written: number;
  api_calls: number;
  source: MetricSourceValue;
  failure_message: string | null;
};
