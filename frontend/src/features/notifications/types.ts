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

export type NotificationKindValue =
  'task_assigned' | 'approval_requested' | 'approval_decided' | 'approval_enacted';

export type NotificationSubjectValue = 'task' | 'approval';

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
