import type { MetricSourceValue } from '@/shared/metrics';

/**
 * The budget API's shapes, mirroring `docs/API.md`.
 *
 * Three things here are load-bearing and must not be tidied away:
 *
 *  - Money is **minor units** on the wire (`amount_minor`), divided once at the
 *    formatter. Amounts sent *back* are decimal strings in the major unit, which is
 *    deliberate: an API that takes "1500.00" and one that takes 150000 look identical in
 *    a request body and differ only on an invoice.
 *  - `minor_units` on a spend total is `number | null`, and null means no total may be
 *    stated — two currencies, a currency the budget is not in, or nothing reported.
 *    Rendering it as 0 would turn "we cannot tell you" into "nothing was spent".
 *  - `projected_minor` is null before a period starts. There is nothing to extrapolate
 *    from, and a zero would read as a forecast of no spend.
 */
export type BudgetStatusValue = 'draft' | 'approved' | 'archived';

export type AllocationLevelValue = 'platform' | 'campaign' | 'ad_group';

export type AllocationPayload = {
  id: string;
  level: AllocationLevelValue;
  level_label: string;
  provider: string;
  provider_label: string;
  label: string;
  amount_minor: number;
  currency: string;
  external_account_id: string | null;
  campaign_id: string | null;
  campaign_name: string | null;
  ad_group_id: string | null;
  ad_group_name: string | null;
  unallocated_minor: number;
  children: AllocationPayload[];
};

export type BudgetPayload = {
  id: string;
  client_id: string;
  client_name: string;
  project_id: string | null;
  project_name: string | null;
  name: string;
  period_start: string;
  period_end: string;
  amount_minor: number;
  currency: string;
  status: BudgetStatusValue;
  status_label: string;
  amount_is_editable: boolean;
  allocations_are_editable: boolean;
  is_paced: boolean;
  approved_by_name: string | null;
  approved_at: string | null;
  allocated_minor: number;
  unallocated_minor: number;
  allocations: AllocationPayload[];
  /** Internal. Absent — not null — from any response a client may read. */
  notes?: string | null;
  updated_at: string | null;
};

export type SpendTotalPayload = {
  /** Null means no total may be stated. Never coerce it. */
  minor_units: number | null;
  currency: string | null;
  source: MetricSourceValue;
  source_label: string;
  unavailable_reason: string | null;
  rows_reported: number;
};

export type PacingStatusValue =
  'not_started' | 'on_track' | 'underspending' | 'overspending' | 'exhausted' | 'ended' | 'unknown';

export type PacingFigures = {
  status: PacingStatusValue;
  status_label: string;
  needs_attention: boolean;
  budget_minor: number;
  spent_minor: number;
  expected_minor: number;
  variance_minor: number;
  variance_percent: number | null;
  remaining_minor: number;
  /** Null before the period starts: nothing to extrapolate from. */
  projected_minor: number | null;
  daily_allowance_minor: number | null;
  total_days: number;
  elapsed_days: number;
  remaining_days: number;
  utilisation: number | null;
  period_elapsed: number | null;
  risks_overspend: boolean;
  risks_under_delivery: boolean;
  currency: string;
  /** Published by the API so the screen does not keep its own copy of the rule. */
  tolerance: number;
};

export type AllocationPacingPayload = {
  allocation_id: string;
  level: AllocationLevelValue;
  level_label: string;
  provider: string;
  label: string;
  parent_label: string | null;
  amount_minor: number;
  currency: string;
  spend: SpendTotalPayload;
  pacing: PacingFigures | null;
  unavailable_reason: string | null;
};

export type PacingPayload = {
  budget: BudgetPayload;
  spend: SpendTotalPayload;
  pacing: PacingFigures | null;
  unavailable_reason: string | null;
  allocations: AllocationPacingPayload[];
  /** One entry per day a provider reported. A gap is a gap, not a zero. */
  daily: Array<{ date: string; spendMinor: number }>;
};

export type ConfidenceValue = 'none' | 'low' | 'medium' | 'high';

export type RateCardPayload = {
  cpm_minor: number | null;
  cpm_low_minor: number | null;
  cpm_high_minor: number | null;
  cpc_minor: number | null;
  cpc_low_minor: number | null;
  cpc_high_minor: number | null;
  ctr: number | null;
  cvr: number | null;
  cvr_low: number | null;
  cvr_high: number | null;
  cpa_minor: number | null;
};

export type ProviderBenchmarkPayload = {
  provider: string;
  provider_label: string;
  observed_days: number;
  currency: string | null;
  confidence: ConfidenceValue;
  confidence_label: string;
  source: MetricSourceValue;
  spend_minor: number | null;
  impressions: number | null;
  clicks: number | null;
  conversions: number | null;
  rates: RateCardPayload;
  unavailable_reason: string | null;
};

export type ProjectionPayload = {
  impressions: number | null;
  impressions_low: number | null;
  impressions_high: number | null;
  clicks: number | null;
  clicks_low: number | null;
  clicks_high: number | null;
  conversions: number | null;
  conversions_low: number | null;
  conversions_high: number | null;
  required_budget_minor: number | null;
  required_budget_low_minor: number | null;
  required_budget_high_minor: number | null;
  /** Which observed rate each figure came from, in words. */
  assumptions: string[];
};

export type ForecastPayload = {
  provider: string;
  provider_label: string;
  confidence: ConfidenceValue;
  confidence_label: string;
  observed_days: number;
  currency: string | null;
  /** Always `estimated`: a projection is never a measurement. */
  source: MetricSourceValue;
  rate_source: MetricSourceValue;
  projection: ProjectionPayload | null;
  /** What could not be worked out, named rather than left blank. */
  gaps: string[];
  unavailable_reason: string | null;
};

export type ForecastResponse = {
  benchmarks: {
    version: string;
    source: string;
    window_start: string;
    window_end: string;
    client_id: string | null;
    project_id: string | null;
    providers: ProviderBenchmarkPayload[];
  };
  forecasts: ForecastPayload[];
  disclaimer: string;
};

/** What the calculator asks for. One direction or the other, never both. */
export type ForecastInput = {
  providers: string[];
  /** Decimal in the major unit, as the API takes it. */
  budget?: string;
  target_metric?: 'conversions' | 'clicks' | 'impressions';
  target_value?: number;
  window_days?: number;
  client_id?: string | null;
  project_id?: string | null;
};

/** A budget being drafted or edited. Amount is a decimal string, as typed. */
export type BudgetDraft = {
  client_id: string;
  project_id: string | null;
  name: string;
  period_start: string;
  period_end: string;
  amount: string;
  currency: string;
  notes: string | null;
};

/** One node of an allocation tree being edited, with a key for the editing session. */
export type AllocationDraft = {
  key: string;
  level: AllocationLevelValue;
  provider: string;
  label: string;
  /** Decimal string, as typed. */
  amount: string;
  campaign_id: string | null;
  ad_group_id: string | null;
  children: AllocationDraft[];
};
