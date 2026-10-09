export const MEMBERSHIP_STATUSES = ['invited', 'active', 'suspended'] as const;

export type MembershipStatus = (typeof MEMBERSHIP_STATUSES)[number];

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
