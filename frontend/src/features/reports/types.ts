import type { MetricValue } from '@/shared/metrics';

/**
 * Reports, as the API publishes them.
 *
 * Four shapes carry the module's argument rather than its data:
 *
 *  - **`comparison.is_comparable` with its `reason`** — whether the change figures may be read at all. Not a
 *    boolean: when it is false the reason is what goes on screen *in place of* the percentages.
 *  - **`mode`** — live or a snapshot. A reader is always told which, because a link that quietly recomputed would
 *    make "the report said 1.2 million" unanswerable.
 *  - **`source` per section as well as per report.** A trend from synced rows beside a ranking from sandbox
 *    fixtures is two claims, and one badge over both presents the weaker as the stronger.
 *  - **`is_empty`** — nothing recorded, which is not the same as a total of zero.
 */

export type ReportWindowValue =
  | 'last_7_days'
  | 'last_14_days'
  | 'last_28_days'
  | 'last_30_days'
  | 'last_90_days'
  | 'last_month'
  | 'month_to_date'
  | 'custom';

export type ComparisonModeValue = 'none' | 'previous_period' | 'same_period_last_year';

export type ReportAudienceValue = 'internal' | 'client_safe';

export type SectionKindValue = 'scorecard' | 'trend' | 'breakdown' | 'table' | 'store' | 'lead';

export type RenderModeValue = 'live' | 'snapshot';

export type ShareStateValue = 'active' | 'revoked' | 'expired';

export type WindowInfo = {
  window: ReportWindowValue;
  label: string;
  needs_dates: boolean;
  /** Month-to-date. Every surface showing it says the period is still running. */
  is_incomplete: boolean;
};

export type ComparisonInfo = {
  comparison: ComparisonModeValue;
  label: string;
  /** What this comparison cannot account for. Null for "no comparison". */
  caveat: string | null;
};

export type SectionKindInfo = {
  kind: SectionKindValue;
  label: string;
  needs_dimension: boolean;
  supports_comparison: boolean;
  metric_cap: number;
  /** False for a store section, which names a revenue source instead of choosing metrics. */
  uses_metrics: boolean;
  /** Which metrics this shape accepts. Reach is absent from a ranking: its rows do not add up. */
  metrics: string[];
};

export type MetricInfo = {
  metric: string;
  label: string;
  format: string;
  is_monetary: boolean;
  lower_is_better: boolean;
  is_additive: boolean;
};

export type RevenueSourceInfo = {
  source: string;
  label: string;
  is_invoiced: boolean;
  /** A model's output. The server refuses it in a report written for a client. */
  is_modelled: boolean;
};

export type DimensionInfo = {
  dimension: string;
  label: string;
};

export type ReportVocabulary = {
  windows: WindowInfo[];
  comparisons: ComparisonInfo[];
  audiences: { audience: ReportAudienceValue; label: string; allows_internal_notes: boolean }[];
  sections: SectionKindInfo[];
  metrics: MetricInfo[];
  dimensions: DimensionInfo[];
  /** Who may be named as the claimant of a store's revenue. */
  revenue_sources: RevenueSourceInfo[];
  max_share_days: number;
};

/*
 * The figures come back in the shape the analytics surfaces already publish, so the one metric formatter in
 * `@/shared/metrics` reads them. It knows that currency arrives in minor units, that a percentage arrives as 2.35
 * rather than 0.0235, and that a null is a missing figure rather than a zero — and a second near-identical type here
 * would mean either a second formatter or a subtle disagreement with the first.
 */
export type RenderedMetricSet = {
  values: Record<string, MetricValue>;
  source: string;
  currency: string | null;
  /** Zero means genuinely no data, which is not the same as a total of zero. */
  row_count: number;
  is_empty: boolean;
};

export type RenderedSection = {
  kind: SectionKindValue;
  title: string;
  metrics: string[];
  dimension: string | null;
  dimension_label: string | null;
  source: string;
  source_label: string;
  requires_label: boolean;
  is_empty: boolean;
  caveats: string[];
  currency: string | null;
  totals: RenderedMetricSet | null;
  series: { date: string; metrics: RenderedMetricSet }[];
  rows: { key: string; label: string; status: string | null; metrics: RenderedMetricSet }[];
  /** Who is claiming the revenue, for a store section; null for every other kind. */
  revenue_source?: string | null;
  revenue_source_label?: string | null;
  store?: StoreFigure[] | null;
  /** The trend, stores, products, channels, customers and refunds behind a store section's figures. */
  store_detail?: StoreDetail | null;
  /** Aggregates of the client's recorded leads, for a lead section. Never a record. */
  lead?: LeadAggregates | null;
};

/**
 * A client's recorded leads, as counts and rates only.
 *
 * There is no field here that could hold a name, a contact point, a note or an owner, and the server does not send one.
 * A rate is null when it has nothing to divide by — that is "no figure", never zero.
 */
export type LeadAggregates = {
  total: number;
  reached: number;
  qualified: number;
  disqualified: number;
  won: number;
  lost: number;
  open: number;
  qualification_rate: number | null;
  win_rate: number | null;
  stages: Record<'new' | 'contacted' | 'qualified' | 'disqualified' | 'won' | 'lost', number>;
  sources: {
    kind: string;
    label: string;
    total: number;
    qualified: number;
    is_measured: boolean;
  }[];
  campaigns: {
    label: string | null;
    is_unattributed: boolean;
    total: number;
    qualified: number;
    won: number;
    closed: number;
  }[];
  response: {
    measured: number;
    unanswered: number;
    median_seconds: number | null;
    /** Leads per bucket: under 5 min, 5–15, 15–60, 1–4 h, 4 h and over. */
    buckets: number[];
  };
};

/**
 * What sits behind a store section's headline figures, for the one authority it names.
 *
 * Money is in minor units of `currency`. Nothing here identifies a person, an order or a store record: a store is its
 * own name, a product is as it was sold, a channel is as the orders carried it. A day that is absent from `daily` had
 * no orders and is a gap, not a zero; a share or a rate is null where it has nothing to divide by.
 */
export type StoreDetail = {
  currency: string;
  daily: { date: string; orders: number; net_revenue: number }[];
  stores: {
    name: string;
    orders: number;
    net_revenue: number;
    refunded: number;
    aov: number | null;
    share: number | null;
  }[];
  products: { label: string; orders: number; units: number; revenue: number }[];
  channels: {
    label: string | null;
    is_unattributed: boolean;
    orders: number;
    net_revenue: number;
  }[];
  attribution_coverage: number | null;
  customers: {
    new: number;
    returning: number;
    unknown: number;
    new_share: number | null;
    unavailable_reason: string | null;
  } | null;
  refunds: { amount: number; orders: number; rate: number | null; previous_rate: number | null };
  previous: { orders: number; net_revenue: number; aov: number | null } | null;
};

/** One store figure. `money` is in minor units of its `currency`; null means there is none to show. */
export type StoreFigure = {
  key: string;
  label: string;
  kind: 'money' | 'count';
  value: number | null;
  currency: string | null;
};

export type RenderedReport = {
  id: string;
  name: string;
  description: string | null;
  client_name: string | null;
  period: { from: string; until: string; days: number; is_incomplete: boolean };
  comparison: {
    mode: ComparisonModeValue;
    label: string;
    from: string | null;
    until: string | null;
    was_requested: boolean;
    is_comparable: boolean;
    reason: string | null;
    code: string | null;
  };
  audience: ReportAudienceValue;
  mode: RenderModeValue;
  mode_label: string;
  source: string;
  source_label: string;
  requires_label: boolean;
  is_empty: boolean;
  /** Null in a client-safe render, because it was never read. */
  internal_note: string | null;
  rendered_at: string;
  sections: RenderedSection[];
};

export type ReportSummary = {
  id: string;
  name: string;
  description: string | null;
  window: ReportWindowValue;
  window_label: string;
  audience: ReportAudienceValue;
  client_name: string | null;
  section_count: number;
  snapshot_count: number;
  /** The figure somebody checks before saying "nobody outside can see this". */
  active_share_count: number;
  updated_at: string;
};

export type SectionDraft = {
  kind: SectionKindValue;
  title: string | null;
  metrics: string[];
  dimension: string | null;
  row_limit: number | null;
  /** Who claims the revenue, for a store section. */
  revenue_source?: string | null;
};

export type ReportDraft = {
  id: string;
  name: string;
  description: string | null;
  client_id: string | null;
  client_name: string | null;
  window: ReportWindowValue;
  custom_from: string | null;
  custom_until: string | null;
  comparison: ComparisonModeValue;
  audience: ReportAudienceValue;
  internal_note: string | null;
  filters: {
    providers: string[];
    campaign_ids: string[];
    project_ids: string[];
    client_id: string | null;
  };
  sections: SectionDraft[];
  created_by_name: string | null;
  updated_at: string;
};

export type ReportShare = {
  id: string;
  /** Twelve characters. The token itself was returned once, when the link was created. */
  hint: string;
  state: ShareStateValue;
  state_label: string;
  is_frozen: boolean;
  expires_at: string;
  revoked_at: string | null;
  revoked_reason: string | null;
  recipient_label: string | null;
  view_count: number;
  last_viewed_at: string | null;
  created_by_name: string | null;
  created_at: string;
};

export type ReportSnapshot = {
  id: string;
  taken_at: string;
  period_from: string;
  period_until: string;
  source: string;
  source_label: string;
  label: string | null;
  taken_by_name: string | null;
};

export type ExportKind = 'report_csv' | 'report_xlsx';

export type ExportJob = {
  id: string;
  kind: ExportKind;
  subject_id: string;
  status: 'queued' | 'running' | 'ready' | 'failed';
  filename: string | null;
  byte_size: number | null;
  error: string | null;
  requested_at: string;
  finished_at: string | null;
  expires_at: string | null;
};

export type ExportCadence = 'daily' | 'weekly' | 'monthly';

export type ExportSchedule = {
  id: string;
  report_id: string;
  kind: ExportKind;
  cadence: ExportCadence;
  hour_utc: number;
  weekday: number | null;
  day_of_month: number | null;
  next_run_at: string | null;
  last_run_at: string | null;
  last_error: string | null;
  is_halted: boolean;
};
