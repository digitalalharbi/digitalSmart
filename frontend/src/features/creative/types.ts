/**
 * Creative operations, as the API publishes them.
 *
 * Three shapes here carry rules rather than data:
 *
 *  - **`allowed_next`** on each state. The board does not carry its own copy of the legal moves, so it can
 *    disable a drop target rather than letting somebody discover the rule by being refused.
 *  - **`verdict`** on a spec report. `pass` is one of five outcomes and the other four are not failures — a
 *    placement nobody has documented, a property nobody could measure, and a specification nobody has re-read
 *    this year all produce something that is not a pass and is not a problem with the file.
 *  - **`visibility`** on a comment. No default anywhere, because an internal note reaching a client ends a
 *    relationship.
 */

export type CreativeStateValue =
  | 'draft'
  | 'briefed'
  | 'in_production'
  | 'in_review'
  | 'changes_requested'
  | 'approved'
  | 'cancelled';

export type AssetKindValue = 'image' | 'video' | 'carousel' | 'copy';

export type SpecVerdictValue = 'pass' | 'fail' | 'unchecked' | 'no_spec' | 'stale';

export type CommentVisibilityValue = 'internal' | 'client';

export type ApprovalOutcomeValue = 'approved' | 'changes_requested';

export type StateInfo = {
  state: CreativeStateValue;
  label: string;
  position: number;
  is_open: boolean;
  is_waiting_on_review: boolean;
  allowed_next: CreativeStateValue[];
  /** Whether a new version landing here undoes an approval. The interface warns before, not after. */
  requires_re_review: boolean;
};

export type KindInfo = {
  kind: AssetKindValue;
  label: string;
  measurable: string[];
};

export type VisibilityInfo = {
  visibility: CommentVisibilityValue;
  label: string;
  is_visible_to_client: boolean;
};

export type CreativeVocabulary = {
  states: StateInfo[];
  kinds: KindInfo[];
  visibilities: VisibilityInfo[];
};

export type SpecProvenance = {
  url: string;
  documented_at: string;
  recorded_by: string | null;
  note: string | null;
  fresh_days: number;
};

export type SpecCheck = {
  requirement: string;
  requirement_label: string;
  verdict: SpecVerdictValue;
  verdict_label: string;
  /** What the file is. Null where nothing could be read off it. */
  measured: string | null;
  /** What the specification asks for, in the words the platform used. */
  expected: string | null;
  detail: string | null;
};

export type SpecReport = {
  verdict: SpecVerdictValue;
  verdict_label: string;
  /** Only a measured failure. See docs/CREATIVE.md. */
  blocks_publishing: boolean;
  provider: string;
  placement: string;
  checks: SpecCheck[];
  source: SpecProvenance | null;
  spec_age_days: number | null;
};

export type CreativeMeasurements = {
  width_px: number | null;
  height_px: number | null;
  aspect_ratio: string | null;
  file_size_bytes: number | null;
  duration_seconds: number | null;
  format: string | null;
  text_length: number | null;
  /** Why something is absent, where the product knows. */
  unmeasured_reason: string | null;
};

export type CreativeVersion = {
  id: string;
  number: number;
  kind: AssetKindValue;
  label: string | null;
  storage_path: string | null;
  body: string | null;
  uploaded_by_name: string | null;
  created_at: string;
  measurements: CreativeMeasurements;
  spec: SpecReport | null;
  decision: ApprovalOutcomeValue | null;
  decision_label: string | null;
  decision_reason: string | null;
  decided_by_name: string | null;
  decided_at: string | null;
  is_approved: boolean;
};

export type CreativeComment = {
  id: string;
  visibility: CommentVisibilityValue;
  visibility_label: string;
  is_visible_to_client: boolean;
  body: string;
  author_name: string | null;
  version_number: number | null;
  created_at: string;
};

export type CreativeCard = {
  id: string;
  client_id: string;
  client_name: string;
  project_id: string | null;
  project_name: string | null;
  title: string;
  state: CreativeStateValue;
  state_label: string;
  kind: AssetKindValue;
  kind_label: string;
  provider: string | null;
  placement: string | null;
  /** The id as well as the name: a picker has to know which option is selected. */
  owner_id: string | null;
  owner_name: string | null;
  due_at: string | null;
  days_until_due: number | null;
  /** Only while the work is open: a finished request is not late. */
  is_overdue: boolean;
  cancelled_reason: string | null;
  state_changed_at: string | null;
  created_at: string;
  version_count: number;
};

export type CreativeRequest = CreativeCard & {
  brief: string | null;
  versions: CreativeVersion[];
  comments: CreativeComment[];
  /** Whether the *newest* version is the approved one. A stale "approved" state shows up here. */
  newest_is_approved: boolean;
};

export type CreativeBoard = {
  columns: { state: CreativeStateValue; label: string; requests: CreativeCard[] }[];
  open: number;
  waiting_on_review: number;
  overdue: number;
};

export type RecordedSpec = {
  provider: string;
  placement: string;
  kind: AssetKindValue;
  requirements: string[];
  source: SpecProvenance;
  is_stale: boolean;
  age_days: number;
};

/** Somebody a brief can be put in the name of: an id and a name, and deliberately nothing else. */
export type CreativeOwner = { id: string; name: string };
