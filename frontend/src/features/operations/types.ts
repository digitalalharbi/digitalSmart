/**
 * Operational work, as the API publishes it.
 *
 * Four shapes here carry rules rather than data:
 *
 *  - **`allowed_next`** on each state. The board does not keep its own copy of the legal moves, so it can
 *    disable an impossible target rather than letting somebody discover the rule by being refused.
 *  - **`horizon`** on a card. `undated` is one of five answers and is not "later": a board that folded the
 *    two together would report a tidy pipeline while a third of its work could not be late.
 *  - **`total` beside `tasks`** on a column. The cards are a page; the total is how many there are.
 *  - **`is_lateness_reportable`** on the workload. False once much of the board carries no date, in which
 *    case the overdue figure describes only the part that could be late, and the page says so.
 */

export type TaskStateValue =
  'todo' | 'in_progress' | 'blocked' | 'in_review' | 'done' | 'cancelled';

export type TaskPriorityValue = 'low' | 'normal' | 'high' | 'urgent';

export type DueHorizonValue = 'overdue' | 'today' | 'this_week' | 'later' | 'undated';

export type TaskSubjectTypeValue = 'creative_request' | 'lead' | 'campaign' | 'budget' | 'project';

export type TaskStateInfo = {
  state: TaskStateValue;
  label: string;
  position: number;
  is_open: boolean;
  is_waiting: boolean;
  /** Blocked and cancelled. The interface asks for the reason before sending, not after being refused. */
  needs_reason: boolean;
  allowed_next: TaskStateValue[];
};

export type TaskPriorityInfo = {
  priority: TaskPriorityValue;
  label: string;
  /** Published because the scale is ordered, and an ordered scale is drawn in order. */
  rank: number;
  is_elevated: boolean;
};

export type DueHorizonInfo = {
  horizon: DueHorizonValue;
  label: string;
  rank: number;
  needs_attention: boolean;
};

export type TaskSubjectTypeInfo = {
  type: TaskSubjectTypeValue;
  label: string;
};

export type TaskVocabulary = {
  states: TaskStateInfo[];
  priorities: TaskPriorityInfo[];
  horizons: DueHorizonInfo[];
  subject_types: TaskSubjectTypeInfo[];
};

export type TaskSubject = {
  type: TaskSubjectTypeValue;
  type_label: string;
  id: string;
  /** Null when the record is gone, not when it is unnamed. */
  label: string | null;
  /** False when the subject has been deleted underneath the task. There is no foreign key behind it. */
  exists: boolean;
  path: string;
};

export type TaskCard = {
  id: string;
  title: string;
  state: TaskStateValue;
  state_label: string;
  priority: TaskPriorityValue;
  priority_label: string;
  client_id: string | null;
  client_name: string | null;
  project_id: string | null;
  project_name: string | null;
  assignee_id: string | null;
  assignee_name: string | null;
  /** A date, with no time in it. */
  due_on: string | null;
  horizon: DueHorizonValue;
  horizon_label: string;
  is_overdue: boolean;
  /** Null with no date: "undated" and "due a long time from now" are different facts. */
  days_until_due: number | null;
  blocked_reason: string | null;
  cancelled_reason: string | null;
  subject: TaskSubject | null;
};

export type Task = TaskCard & {
  description: string | null;
  started_at: string | null;
  completed_at: string | null;
  state_changed_at: string | null;
  created_at: string;
  /** Null while unfinished, and null when nobody recorded a start — unknown, not zero. */
  working_days: number | null;
};

export type TaskColumn = {
  state: TaskStateValue;
  label: string;
  /** The whole column, in SQL. Not the length of `tasks`, which is a page of it. */
  total: number;
  tasks: TaskCard[];
};

export type TaskWorkload = {
  total: number;
  open: number;
  done: number;
  cancelled: number;
  overdue: number;
  /** Published beside overdue rather than folded into it. */
  undated: number;
  unassigned: number;
  elevated: number;
  waiting: number;
  by_state: Record<string, number>;
  by_horizon: Record<string, number>;
  /** Null, never zero, when nothing has settled. */
  completion_rate: number | null;
  dated_share: number | null;
  is_lateness_reportable: boolean;
  dated_share_floor: number;
};

export type AssigneeLoad = {
  /** Null for the unassigned pile, which is a row like any other. */
  user_id: string | null;
  name: string | null;
  is_unassigned: boolean;
  open: number;
  overdue: number;
  by_priority: Record<string, number>;
};

export type TaskBoard = {
  columns: TaskColumn[];
  workload: TaskWorkload;
  load: AssigneeLoad[];
  /** The priorities actually present, so a legend carries no entry for urgency nothing has. */
  priorities_present: TaskPriorityValue[];
  is_truncated: boolean;
  as_of: string;
};
