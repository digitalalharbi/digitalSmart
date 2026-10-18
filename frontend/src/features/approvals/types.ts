/**
 * Approvals, as the API publishes them.
 *
 * Three fields carry the module's argument rather than its data:
 *
 *  - **`is_enacted_here`** — false for every kind today. This product does not write to the ad platforms, so
 *    approving a pause pauses nothing, and the row says so rather than leaving a reader to assume.
 *  - **`enactment`** — `awaiting_hand` is the state an approved change sits in until a person records making
 *    it. The count of those is the figure this module exists for.
 *  - **`fingerprint`** — a decision has to send back the one its screen displayed. A mismatch is a 409, not a
 *    validation failure: the request was correct when it was read.
 */

export type ApprovalKindValue =
  | 'campaign_launch'
  | 'campaign_pause'
  | 'spend_change'
  | 'targeting_change'
  | 'creative_publish'
  | 'tracking_change';

export type ApprovalStateValue = 'pending' | 'approved' | 'declined' | 'withdrawn';

export type EnactmentValue = 'not_required' | 'awaiting_hand' | 'confirmed' | 'abandoned';

export type ApprovalAgeValue = 'today' | 'days' | 'week' | 'stale';

export type ApprovalKindInfo = {
  kind: ApprovalKindValue;
  label: string;
  touches_live_spend: boolean;
  /** False for every kind today. See the note above. */
  is_enacted_here: boolean;
  enactment_blocked_because: string | null;
  /** A permission the decider needs on top of `approvals.decide`, or null. */
  decided_with: string | null;
  usual_subject: string | null;
};

export type ApprovalStateInfo = {
  state: ApprovalStateValue;
  label: string;
  position: number;
  is_pending: boolean;
  needs_reason: boolean;
  is_amendable: boolean;
};

export type EnactmentInfo = {
  enactment: EnactmentValue;
  label: string;
  is_outstanding: boolean;
  is_settled: boolean;
};

export type ApprovalAgeInfo = {
  age: ApprovalAgeValue;
  label: string;
  rank: number;
  needs_attention: boolean;
};

export type ApprovalVocabulary = {
  kinds: ApprovalKindInfo[];
  states: ApprovalStateInfo[];
  enactments: EnactmentInfo[];
  ages: ApprovalAgeInfo[];
  patience_days: number;
};

export type ApprovalSubject = {
  type: string;
  type_label: string;
  id: string;
  label: string | null;
  exists: boolean;
  path: string;
};

export type ApprovalRow = {
  id: string;
  kind: ApprovalKindValue;
  kind_label: string;
  title: string;
  /** Why it was asked for, in the requester's words. Short; the card shows the proposal, this is the detail. */
  rationale: string;
  /** What is being proposed. Free-form by design; a budget change carries `current`, `requested` and `currency`. */
  proposal: Record<string, unknown>;
  state: ApprovalStateValue;
  state_label: string;
  enactment: EnactmentValue;
  enactment_label: string;
  touches_live_spend: boolean;
  is_enacted_here: boolean;
  enactment_blocked_because: string | null;
  /**
   * Whether this product can carry the change out on the platform.
   *
   * Different from `is_enacted_here`, which asks whether *approving* enacts it — still false for every kind,
   * because approval authorises and carrying out is a second act. This asks whether that second act is
   * available, which depends on the proposal naming a platform campaign and on the provider's write having
   * been read in its own documentation.
   */
  can_be_carried_out_here: boolean;
  carry_out_blocked_because: string | null;
  /** False once somebody has amended the proposal. The write path refuses on it. */
  proposal_matches_decision: boolean;
  /** The hash a decision must send back. */
  fingerprint: string;
  fingerprint_short: string;
  client_id: string | null;
  client_name: string | null;
  project_id: string | null;
  project_name: string | null;
  requested_by_name: string | null;
  created_at: string;
  decided_by_name: string | null;
  decided_at: string | null;
  /** Null when nothing is waiting, which differs from waiting no time at all. */
  age: ApprovalAgeValue | null;
  age_label: string | null;
  waiting_days: number | null;
  subject: ApprovalSubject | null;
};

export type ApprovalRequest = ApprovalRow & {
  decision_reason: string | null;
  withdrawn_reason: string | null;
  enactment_note: string | null;
  enacted_by_name: string | null;
  enacted_at: string | null;
  supersedes_id: string | null;
  decision_days: number | null;
};

export type ApprovalLoad = {
  pending: number;
  pending_touching_spend: number;
  approved: number;
  declined: number;
  withdrawn: number;
  /** Approved changes nobody has carried out. The figure this module exists for. */
  outstanding: number;
  confirmed: number;
  abandoned: number;
  /** Null, not zero, with nothing waiting. */
  oldest_outstanding_days: number | null;
  oldest_pending_days: number | null;
  pending_by_age: Record<string, number>;
  outstanding_by_age: Record<string, number>;
  pending_by_kind: Record<string, number>;
  /** Null until something has settled. */
  carried_out_rate: number | null;
  approval_rate: number | null;
  needs_attention: boolean;
  patience_days: number;
};

export type ApprovalQueue = {
  pending: ApprovalRow[];
  outstanding: ApprovalRow[];
  settled: ApprovalRow[];
  load: ApprovalLoad;
  as_of: string;
};

/** What a platform did when asked to change something. */
export type WriteOutcome = {
  is_confirmed: boolean;
  operation: string;
  provider: string;
  provider_label: string;
  /** What the provider reported when the campaign was read back. Null when nothing was confirmed. */
  confirmed_state: string | null;
  failure: string | null;
  failure_label: string | null;
  guidance: string | null;
  /**
   * False for an unknown outcome, which is the only reason that case exists: a reader told "it failed" tries
   * again, and trying again after an unknown outcome is how a budget gets set twice.
   */
  safe_to_retry: boolean | null;
  provider_message: string | null;
  provider_request_id: string | null;
  source: string;
  source_label: string;
  summary: string;
};
