/**
 * The analytics API's shapes, mirroring `docs/API.md`.
 *
 * Two things here are load-bearing and must not be "simplified" away:
 *
 *  - `value` is `number | null`, and null means the figure does not exist.
 *    Defaulting it to 0 anywhere in this app turns a reporting gap into a
 *    performance claim, which is the one failure this product treats as
 *    unrecoverable.
 *  - Money arrives in **minor units** (`is_minor_units`). Dividing by 100 happens
 *    once, at the formatter, so nothing rounds twice.
 */
export type MetricSourceValue =
  'live' | 'synced' | 'stale' | 'partial' | 'sandbox' | 'manual' | 'estimated';

export type MetricFormatValue = 'currency' | 'count' | 'percentage' | 'ratio';

export type MetricValue = {
  metric: string;
  label: string;
  /** Null means not reported. Never coerce it. */
  value: number | null;
  format: MetricFormatValue;
  precision: number;
  is_minor_units: boolean;
  currency: string | null;
  source: MetricSourceValue;
  source_label: string;
  requires_label: boolean;
  /** Why there is no value, in words a person can act on. */
  unavailable_reason: string | null;
  comparison_value: number | null;
  change_ratio: number | null;
  change_is_good: boolean | null;
  lower_is_better: boolean;
};

export type MetricSetPayload = {
  source: MetricSourceValue;
  currency: string | null;
  row_count: number;
  values: Record<string, MetricValue>;
};

export type SeriesPointPayload = MetricSetPayload & {
  date: string;
};

export type BreakdownRowPayload = MetricSetPayload & {
  dimension: string;
  key: string;
  label: string;
  external_id: string | null;
  status: string | null;
  parent_key: string | null;
};

export type AnalyticsPayload = {
  range: { start: string; end: string; days: number };
  compare_to: { start: string; end: string } | null;
  metrics: string[];
  source: MetricSourceValue;
  source_label: string;
  requires_label: boolean;
  is_empty: boolean;
  summary: MetricSetPayload;
  series: SeriesPointPayload[];
  breakdown: BreakdownRowPayload[];
  dimension: string | null;
};

export type MetricDefinition = {
  value: string;
  label: string;
  format: MetricFormatValue;
  precision: number;
  is_derived: boolean;
  is_monetary: boolean;
  /** False for reach: the UI explains why it vanishes on multi-entity selections. */
  additive_across_entities: boolean;
  lower_is_better: boolean;
  depends_on: string[];
};

export type CataloguePayload = {
  metrics: MetricDefinition[];
  dimensions: { value: string; label: string; level: string | null }[];
  providers: { value: string; label: string }[];
  sources: {
    value: MetricSourceValue;
    label: string;
    requires_label: boolean;
    is_actual: boolean;
  }[];
};

export type DatePreset = '7d' | '14d' | '28d' | '30d' | '90d' | 'this_month' | 'last_month';
export type ComparisonMode = 'none' | 'previous_period' | 'previous_year';
export type DimensionValue =
  'date' | 'provider' | 'external_account' | 'campaign' | 'ad_group' | 'ad';

export type AnalyticsQuery = {
  preset?: DatePreset;
  start?: string;
  end?: string;
  compare?: ComparisonMode;
  metrics?: string[];
  dimension?: DimensionValue | null;
  order_by?: string;
  limit?: number;
  providers?: string[];
  account_ids?: string[];
  campaign_ids?: string[];
  ad_group_ids?: string[];
  ad_ids?: string[];
  project_ids?: string[];
  client_id?: string | null;
};
