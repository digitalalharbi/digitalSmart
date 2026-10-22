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
 * Eleven, and the labels are the server's: this union exists so a `kind` that stops being announced fails the
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
  | 'client_request_replied'
  | 'digest_ready'
  | 'export_ready'
  | 'performance_digest'
  | 'roas_drop'
  | 'cost_per_result_rise'
  | 'spend_spike'
  | 'budget_near_limit'
  | 'no_results'
  | 'tracking_failure'
  | 'campaign_rejected'
  | 'creative_fatigue'
  | 'campaign_ended'
  | 'campaign_under_delivering';

export type NotificationSubjectValue =
  | 'task'
  | 'approval'
  | 'client_request'
  | 'lead'
  | 'digest'
  | 'export'
  | 'campaign'
  | 'budget'
  | 'creative'
  | 'measurement_source';

export type NotificationSeverityValue = 'info' | 'success' | 'attention' | 'critical';

export type NotificationCategoryValue =
  'performance' | 'budget' | 'creative' | 'tracking' | 'approvals' | 'reports' | 'work';

export type NotificationItem = {
  id: string;
  kind: NotificationKindValue;
  kind_label: string;
  category: NotificationCategoryValue;
  category_label: string;
  /** Decided by a rule when it was announced, and stored. */
  severity: NotificationSeverityValue;
  severity_label: string;
  /** The figures a card draws; null for the kinds that carry a sentence and a link only. */
  payload: Record<string, unknown> | null;
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
  clicked_at: string | null;
  dismissed_at: string | null;
  created_at: string;
};

export type NotificationDigest = {
  total: number;
  unread: number;
  /** Unread and of a kind the reader is expected to act on. */
  waiting: number;
  unread_by_kind: Record<string, number>;
  /** Unread by tab and by severity — grouped server-side so the badges agree with the list. */
  unread_by_category: Record<NotificationCategoryValue, number>;
  unread_by_severity: Record<NotificationSeverityValue, number>;
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
  category?: NotificationCategoryValue;
  requires: string;
  subject: NotificationSubjectValue;
};

export type NotificationVocabulary = {
  kinds: NotificationKindInfo[];
  categories?: { category: NotificationCategoryValue; label: string }[];
  severities?: { severity: NotificationSeverityValue; label: string; rank: number }[];
};

export type DigestChannelValue = 'in_app' | 'email' | 'whatsapp';

/**
 * What one person asked for. `timezone` null follows the workspace; `project_ids` null means every project they can
 * see. `channel_availability` is the server's own word on what each channel can do today — nothing is promised.
 */
export type NotificationPreferences = {
  daily: boolean;
  weekly: boolean;
  monthly: boolean;
  channels: DigestChannelValue[];
  timezone: string | null;
  hour: number;
  client_id: string | null;
  project_ids: string[] | null;
  muted_categories: NotificationCategoryValue[];
  channel_availability: {
    channel: DigestChannelValue;
    label: string;
    availability: 'available' | 'awaiting_credentials';
  }[];
};

export type NotificationPreferencesInput = Omit<NotificationPreferences, 'channel_availability'>;
