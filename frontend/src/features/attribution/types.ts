/**
 * The attribution layer's vocabulary, as the API publishes it.
 *
 * Two fields here carry more weight than their types suggest:
 *
 *  - **`is_modelled`.** True for every model except one. A figure from a modelled attribution is a
 *    claim about which campaign deserves credit, not a record of what happened, and no surface may
 *    show one without saying so.
 *  - **`is_available`.** False for the three models that need the sequence of marketing touches before
 *    a conversion, which this product does not hold. They are published anyway, with `missing` and
 *    `would_unblock`, so the interface can offer them and explain them rather than leaving a gap
 *    somebody asks about every quarter.
 */

export type AttributionModelValue =
  | 'last_recorded'
  | 'first_recorded'
  | 'paid_last_click'
  | 'linear'
  | 'position_based'
  | 'time_decay';

export type AttributionModelInfo = {
  model: AttributionModelValue;
  label: string;
  description: string;
  is_modelled: boolean;
  is_available: boolean;
  needs_person: boolean;
  requires: string;
  requires_label: string;
  /** What the data lacks, for an unavailable model. */
  missing: string | null;
  /** What would make it computable. Concrete, because somebody has to decide whether to pay for it. */
  would_unblock: string | null;
};

/** How firmly a row ties to an ad campaign, which decides whether spend may sit beside its revenue. */
export type CampaignLinkValue =
  'exact_name' | 'click_not_resolved' | 'no_matching_campaign' | 'channel_only';

export type AttributionCoverage = {
  conversions: number;
  attributed_conversions: number;
  campaign_named_conversions: number;
  revenue_minor: number | null;
  attributed_revenue_minor: number | null;
  /** Revenue no campaign can be credited with. Published rather than left to subtraction. */
  unattributed_revenue_minor: number | null;
  currency: string | null;
  conversion_coverage: number | null;
  revenue_coverage: number | null;
  campaign_named_share: number | null;
  /** The floor of what the figure rests on, not the average. */
  weakest_evidence: string;
  weakest_evidence_label: string;
  confidence: number | null;
  is_reportable: boolean;
  reportable_threshold: number;
};

export type AttributedRow = {
  label: string;
  link: CampaignLinkValue;
  link_label: string;
  /** Why an empty spend cell is empty. A blank is a report somebody distrusts. */
  link_explanation: string | null;
  joins_spend: boolean;
  provider: string | null;
  conversions: number;
  revenue_minor: number | null;
  currency: string | null;
  average_revenue_minor: number | null;
  evidence: string;
  evidence_label: string;
  confidence: number;
  spend_minor: number | null;
  spend_source: string | null;
  /** Null wherever dividing would be meaningless. See docs/ATTRIBUTION.md. */
  roas: number | null;
  cost_per_conversion_minor: number | null;
};

export type AttributionAnswer = {
  model: AttributionModelValue;
  model_label: string;
  is_modelled: boolean;
  revenue_source: string;
  revenue_source_label: string;
  is_invoiced: boolean;
  lookback_days: number;
  from: string;
  until: string;
  coverage: AttributionCoverage;
  rows: AttributedRow[];
  /** Ad spend no attributed revenue was matched to. Half the question an agency is asked. */
  unmatched_spend_minor: number | null;
  spend_currency: string | null;
  /** What this answer cannot say, assembled per request. */
  caveats: string[];
};

export type Disagreement = {
  label: string;
  /** Null means the model's breakdown was truncated, so its figure for this campaign is unknown. */
  revenue_minor_by_model: Record<string, number | null>;
  currency: string | null;
  highest_minor: number | null;
  lowest_minor: number | null;
  spread_percent: number | null;
  is_material: boolean;
};

export type AttributionComparison = {
  models: AttributionModelValue[];
  revenue_source: string;
  from: string;
  until: string;
  /** Campaigns where the choice of model moves the figure enough to change a decision. */
  material_disagreements: number;
  campaigns: Disagreement[];
  views: Record<string, AttributionAnswer>;
};

export type AttributionParams = {
  model: AttributionModelValue;
  revenue_source: string;
  from: string;
  until: string;
  client_id?: string | null;
  lookback_days?: number;
  limit?: number;
};

export type ComparisonParams = Omit<AttributionParams, 'model'>;

/** The refusal body an unavailable model produces, so the interface can explain rather than retry. */
export type ModelRefusal = {
  code: string;
  model: AttributionModelValue;
  missing: string | null;
  would_unblock: string | null;
};
