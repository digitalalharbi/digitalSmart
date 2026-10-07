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
  state: ApprovalStateValue;
  state_label: string;
  enactment: EnactmentValue;
  enactment_label: string;
  touches_live_spend: boolean;
  is_enacted_here: boolean;
  enactment_blocked_because: string | null;
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
  rationale: string;
  proposal: Record<string, unknown>;
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
