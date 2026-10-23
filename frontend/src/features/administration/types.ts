/**
 * Every status the server can report, which is not the same list as the one worth filtering by.
 *
 * `invited` is in the type because a `Member.status` could carry it and the type has to admit that; it is
 * absent from `FILTERABLE_STATUSES` below because this installation never writes one.
 *
 * A union rather than an array to derive from: nothing needs these three at runtime, and a const array kept
 * only to produce a type is a value that ships for nobody — which is exactly what ESLint said about it.
 */
export type MembershipStatus = 'invited' | 'active' | 'suspended';

/**
 * The statuses worth filtering the directory by.
 *
 * `invited` is deliberately absent: a member in that state cannot sign in at all, so this installation never
 * creates one — `InviteMember` writes `active` and a one-time password instead. Offering it would be a filter
 * that is always empty, and a reader would take that for a bug in the filter rather than a fact about the
 * product. See `docs/SECURITY.md`.
 */
export const FILTERABLE_STATUSES = [
  'active',
  'suspended',
] as const satisfies readonly MembershipStatus[];

export const WORKSPACE_ROLES = [
  'owner',
  'admin',
  'project-manager',
  'media-buyer',
  'creative',
  'client-viewer',
] as const;

export type WorkspaceRole = (typeof WORKSPACE_ROLES)[number];

export type Member = {
  user_id: string;
  name: string;
  email: string;
  status: MembershipStatus;
  role: WorkspaceRole;
  role_name: string;
  job_title: string | null;
  is_active: boolean;
  last_login_at: string | null;
  joined_at: string | null;
  project_count: number;
};

export type AuditEntry = {
  id: string;
  action: string;
  group: string;
  is_security_event: boolean;
  actor: { id: string | null; name: string | null } | null;
  auditable: { type: string | null; id: string | null } | null;
  summary: string | null;
  changes: Record<string, unknown>;
  ip_address: string | null;
  request_id: string | null;
  created_at: string | null;
};

/**
 * A colleague who has just been added.
 *
 * `one_time_password` is empty when an existing account was joined to this workspace rather than created: that
 * person already has a password they chose, and inventing a string to show the administrator would be a lie
 * about what happened. It is readable **once**, in this response, and is stored nowhere.
 */
export type InvitedMember = {
  user_id: string;
  name: string;
  email: string;
  role: WorkspaceRole;
  one_time_password: string;
  is_existing_account: boolean;
};

export type MemberDraft = { name: string; email: string; role: WorkspaceRole };

/**
 * What somebody still has in their name.
 *
 * Four tables carry an assignment, so four counts. Removal releases the open ones and reports the same shape
 * back, which is why the dialog can say what it is about to do and then what it did.
 */
export type HeldWork = {
  tasks: number;
  leads: number;
  client_requests: number;
  creative_requests: number;
  total: number;
};

export type AccessRequestStatus = 'pending' | 'contacted' | 'approved' | 'declined';

export type AccessRequest = {
  id: string;
  name: string;
  email: string;
  company: string | null;
  phone: string | null;
  need: 'self_managed' | 'agency_clients' | 'ad_services';
  need_label: string;
  message: string | null;
  locale: string;
  status: AccessRequestStatus;
  created_at: string;
  reviewed_at: string | null;
  reviewed_by_name: string | null;
  review_note: string | null;
};
