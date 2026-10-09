/**
 * Notifications, as the API publishes them.
 *
 * Two fields carry the module's thinking.
 *
 * **`waiting`**, not `unread`, is what a bell should show. "Twelve unread" measures how long somebody has been
 * away; "two waiting on you" is a to-do list.
 *
 * **`is_live`** says whether this session is being pushed to or is polling. Published rather than assumed: a
 * page that implied it was live while polling is a page where somebody waits for an update that never comes.
 */

/**
 * Every kind the server can announce.
 *
 * Ten, and the labels are the server's: this union exists so a `kind` that stops being announced fails the
 * build rather than becoming a branch nobody takes. Four of them are about a client request, because the
 * recipient and the action differ — a client told their request moved has nothing to do, a client asked for
 * information has, an agency told a client replied has, and "the agency answered you" goes the other way.
 *
 * `lead_assigned` was missing from this union while the server had been announcing it for two phases, which
 * is the drift the vocabulary panel on the notification centre now makes visible: the list a reader sees is
 * the server's, not a sentence somebody kept in step by hand.
 */
export type NotificationKindValue =
  | 'task_assigned'
  | 'lead_assigned'
  | 'approval_requested'
  | 'approval_decided'
  | 'approval_enacted'
  | 'client_request_submitted'
  | 'client_request_moved'
  | 'client_request_needs_information'
  | 'client_request_answered'
  | 'client_request_replied';

export type NotificationSubjectValue = 'task' | 'approval' | 'client_request';

export type NotificationItem = {
  id: string;
  kind: NotificationKindValue;
  kind_label: string;
  needs_action: boolean;
  /** The sentence as it was announced. Never re-rendered from the subject. */
  title: string;
  body: string | null;
  subject: {
    type: NotificationSubjectValue;
    type_label: string;
    id: string;
    label: string | null;
    /** False when the thing has been deleted. */
    exists: boolean;
    path: string;
  };
  actor_name: string | null;
  is_unread: boolean;
  is_waiting: boolean;
  read_at: string | null;
  created_at: string;
};

export type NotificationDigest = {
  total: number;
  unread: number;
  /** Unread and of a kind the reader is expected to act on. */
  waiting: number;
  unread_by_kind: Record<string, number>;
};

export type NotificationFeed = {
  notifications: NotificationItem[];
  digest: NotificationDigest;
  is_live: boolean;
};

export type NotificationKindInfo = {
  kind: NotificationKindValue;
  label: string;
  needs_action: boolean;
  requires: string;
  subject: NotificationSubjectValue;
};

export type NotificationVocabulary = {
  kinds: NotificationKindInfo[];
};
