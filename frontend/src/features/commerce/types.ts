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
};

export type StoreAnalytics = {
  from: string;
  until: string;
  summary: StoreRevenueSummary;
  by_channel: RevenueBreakdownRow[];
  by_campaign: RevenueBreakdownRow[];
  by_product: RevenueBreakdownRow[];
  daily: DailyRevenuePoint[];
};

export type CommerceQuery = {
  revenue_source: RevenueSourceValue;
  from?: string;
  until?: string;
  client_id?: string | null;
  limit?: number;
};
