/**
 * The Lead Center's vocabulary, as the API publishes it.
 *
 * Mirrored from the server rather than invented here. Two things in this shape are
 * load-bearing and look like ordinary optional fields:
 *
 *  - **Money is nullable and always arrives with its currency.** Null means the figure is
 *    not reportable — the window's valued leads came in more than one currency, and a sum
 *    of minor units across currencies is not a smaller or larger number, it is not a
 *    number. `currency_count` says how many, so the interface can explain the gap.
 *  - **Every rate is nullable.** Null means the denominator was empty. `0%` and "nothing
 *    to measure" look identical on a card and mean opposite things.
 */

export type LeadStage = 'new' | 'contacted' | 'qualified' | 'disqualified' | 'won' | 'lost';

export type LeadFunnel = {
  total: number;
  contacted: number;
  reached: number;
  qualified: number;
  disqualified: number;
  won: number;
  lost: number;
  open: number;
  closed: number;
  won_value_minor: number | null;
  open_value_minor: number | null;
  currency: string | null;
  currency_count: number;
  is_value_reportable: boolean;
  win_rate: number | null;
  qualification_rate: number | null;
  contact_rate: number | null;
  average_won_value_minor: number | null;
};

export type LeadSourceRow = {
  source_kind: string;
  source_kind_label: string;
  source_detail: string | null;
  /** False for a hand-entered or imported lead, which must never be reported as a campaign result. */
  is_measured: boolean;
  total: number;
  qualified: number;
  won: number;
  closed: number;
  won_value_minor: number | null;
  currency: string | null;
  win_rate: number | null;
};

export type LeadCampaignRow = {
  label: string;
  total: number;
  qualified: number;
  won: number;
  closed: number;
  evidence: string | null;
  evidence_label: string | null;
  confidence: number | null;
  /** The row that holds the leads nothing identified. Shown, never hidden. */
  is_unattributed: boolean;
  win_rate: number | null;
};

export type LeadCentre = {
  window_days: number;
  funnel: LeadFunnel;
  by_source: LeadSourceRow[];
  by_campaign: LeadCampaignRow[];
  /** Counted taps and form starts, by raw event name. Beside the funnel, never inside it. */
  signals: Record<string, number>;
  signals_note: string;
};

export type Lead = {
  id: string;
  client_id: string;
  client_name: string | null;
  project_id: string | null;
  source_kind: string;
  source_kind_label: string;
  source_detail: string | null;
  is_measured: boolean;
  stage: LeadStage;
  stage_label: string;
  is_closed: boolean;
  disqualified_reason: string | null;
  owner_id: string | null;
  owner_name: string | null;
  name: string | null;
  email: string | null;
  phone: string | null;
  message: string | null;
  value_minor: number | null;
  currency: string | null;
  evidence: string;
  evidence_label: string;
  confidence: number;
  utm_source: string | null;
  utm_campaign: string | null;
  received_at: string;
  first_contacted_at: string | null;
  qualified_at: string | null;
  closed_at: string | null;
  /** How long somebody took to respond. The figure that separates a bad campaign from a slow team. */
  hours_to_first_contact: number | null;
  /**
   * The lead this one was merged into, where somebody decided two records were one person.
   *
   * Non-null means this record is counted nowhere and the person's history continues elsewhere. The lists never
   * return one, so a surface holding it arrived by an old link and should send the reader to the survivor.
   */
  merged_into_id: string | null;
  merged_at: string | null;
  /** How many other enquiries are on this record. Zero for almost every lead. */
  absorbed_count: number;
};

/**
 * Another lead that may be the same person.
 *
 * `matched_on_label` is not decoration. A merge cannot be undone, and a suggestion with no evidence beside it
 * is one somebody accepts without looking — so the row says which contact point matched, exactly. Nothing in
 * this product matches on a name.
 */
export type LeadDuplicate = {
  id: string;
  source_kind: string;
  source_kind_label: string;
  source_detail: string | null;
  stage: LeadStage;
  stage_label: string;
  name: string | null;
  email: string | null;
  phone: string | null;
  received_at: string;
  matched_on: 'email' | 'phone' | 'email_and_phone';
  matched_on_label: string;
};

export type LeadMerge = {
  leadId: string;
  otherLeadId: string;
  stage: LeadStage;
  note?: string;
};

export type Touchpoint = {
  kind: string;
  kind_label: string;
  id: string;
  occurred_at: string;
  summary: string;
  state: string | null;
  attribution_evidence: string;
  attribution_evidence_label: string;
  attribution_confidence: number;
  campaign: string | null;
  source: string | null;
  value_minor: number | null;
  currency: string | null;
};

export type Journey = {
  name: string | null;
  identity_evidence: string;
  identity_evidence_label: string;
  identity_confidence: number;
  /** Whether these records are solidly one person, or merely probably. */
  is_reliable: boolean;
  touchpoint_count: number;
  /** False for a single record, which is not a journey and must not be drawn as one. */
  is_multi_touch: boolean;
  span_in_days: number | null;
  recorded_value_minor: number | null;
  earliest_attributed_touchpoint: Touchpoint | null;
  touchpoints: Touchpoint[];
  /** What this journey cannot say. Carried as data because the reader is who it protects. */
  limitations: string[];
};

export type LeadScope = {
  client_id?: string | null;
  days?: number | null;
};

export type LeadListFilter = {
  client_id?: string | null;
  stage?: LeadStage[];
  limit?: number;
};

export type StageChange = {
  leadId: string;
  stage: LeadStage;
  /** Required by the server for a disqualification, which refuses one without a reason. */
  reason?: string;
  note?: string;
};
