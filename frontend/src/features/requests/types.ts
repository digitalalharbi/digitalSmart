/**
 * Client requests, as the API publishes them.
 *
 * Three fields carry the module's argument rather than its data:
 *
 *  - **`is_agency_view`** — false when a client is reading their own requests. The page needs it: a surface that
 *    did not know which side it was showing would offer an "internal note" control that silently produced a
 *    client-visible message.
 *  - **`stated_urgency`** — what the **client** said, never the board's priority. It has its own scale and its own
 *    labels for exactly that reason, and the tasks raised from a request carry the agency's own priority instead.
 *  - **`promised_at` with `promised_by_name`** — a date the agency committed to, with the person who committed it.
 *    Null means nothing was promised, which is a fact the queue publishes rather than hides.
 */

export type ClientRequestStateValue =
  | 'submitted'
  | 'needs_information'
  | 'accepted'
  | 'in_progress'
  | 'delivered'
  | 'declined'
  | 'withdrawn';

export type ClientRequestKindValue =
  'new_campaign' | 'change' | 'creative' | 'report' | 'question' | 'other';

export type StatedUrgencyValue = 'whenever' | 'this_month' | 'this_week' | 'urgent';

export type MessageAudienceValue = 'internal' | 'client';

export type RequestStateInfo = {
  state: ClientRequestStateValue;
  label: string;
  position: number;
  is_open: boolean;
  /** Both published: a single "open" figure would let a stall on the client hide behind the agency's column. */
  is_on_the_agency: boolean;
  is_on_the_client: boolean;
  needs_reason: boolean;
  allowed_next: ClientRequestStateValue[];
};

export type RequestKindInfo = {
  kind: ClientRequestKindValue;
  label: string;
  /** False for a question, which needs an answer rather than a card on a board. */
  produces_work: boolean;
};

export type UrgencyInfo = {
  urgency: StatedUrgencyValue;
  label: string;
  rank: number;
};

export type AudienceInfo = {
  audience: MessageAudienceValue;
  label: string;
  is_visible_to_client: boolean;
};

export type RequestVocabulary = {
  states: RequestStateInfo[];
  kinds: RequestKindInfo[];
  urgencies: UrgencyInfo[];
  audiences: AudienceInfo[];
  patience_days: number;
};

export type RequestMessage = {
  id: string;
  audience: MessageAudienceValue;
  is_visible_to_client: boolean;
  body: string;
  author_name: string | null;
  is_from_client: boolean;
  created_at: string;
};

export type RequestLinkedTask = {
  id: string;
  title: string;
  state: string;
  state_label: string;
  assignee_name: string | null;
};

export type ClientRequestCard = {
  id: string;
  client_id: string;
  client_name: string;
  kind: ClientRequestKindValue;
  kind_label: string;
  title: string;
  state: ClientRequestStateValue;
  state_label: string;
  is_on_the_client: boolean;
  /** The client's word. Never rendered as a priority. */
  stated_urgency: StatedUrgencyValue;
  stated_urgency_label: string;
  project_id: string | null;
  project_name: string | null;
  /** What the client asked for. A wish. */
  wanted_by: string | null;
  /** What the agency agreed, with who agreed it. Null means nothing was promised. */
  promised_at: string | null;
  promised_by_name: string | null;
  is_overdue: boolean;
  is_unanswered: boolean;
  /** Still running while unanswered, which is what makes the queue's oldest figure honest. */
  waiting_for_answer_days: number;
  created_at: string;
};

export type ClientRequest = ClientRequestCard & {
  detail: string | null;
  declined_reason: string | null;
  needed_information: string | null;
  /** Null on the client's side: who inside an agency is handling something is the agency's business. */
  submitted_by_name: string | null;
  owner_name: string | null;
  first_answered_at: string | null;
  delivered_at: string | null;
  state_changed_at: string | null;
  turnaround_days: number | null;
  messages: RequestMessage[];
  /** Empty on the client's side. The agency's plan of work is the same leak an internal note is. */
  tasks: RequestLinkedTask[];
};

export type IntakeColumn = {
  state: ClientRequestStateValue;
  label: string;
  /** The whole column, in SQL. Not the length of `requests`, which is a page of it. */
  total: number;
  requests: ClientRequestCard[];
};

export type IntakeLoad = {
  total: number;
  open: number;
  /** The pair that stops a stalled conversation hiding behind a reassuring column. */
  on_the_agency: number;
  on_the_client: number;
  delivered: number;
  declined: number;
  withdrawn: number;
  promised: number;
  /** Accepted with no agreed date. "We are on it" is the whole of what those clients were told. */
  unpromised: number;
  overdue: number;
  /** What clients called urgent. Their word. */
  called_urgent: number;
  /** Null, not zero, when nothing is unanswered. */
  oldest_unanswered_days: number | null;
  by_state: Record<string, number>;
  by_kind: Record<string, number>;
  by_urgency: Record<string, number>;
  /** Null until something has closed. */
  delivery_rate: number | null;
  promised_share: number | null;
  needs_attention: boolean;
  patience_days: number;
};

export type IntakeQueue = {
  columns: IntakeColumn[];
  load: IntakeLoad;
  is_agency_view: boolean;
  can_triage: boolean;
  as_of: string;
};
