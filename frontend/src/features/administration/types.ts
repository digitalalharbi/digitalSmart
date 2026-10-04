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
