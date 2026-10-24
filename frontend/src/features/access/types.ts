export type AccessKind =
  'account_access' | 'general_enquiry' | 'demo_request' | 'partnership' | 'other';

export const ACCESS_KINDS: AccessKind[] = [
  'account_access',
  'general_enquiry',
  'demo_request',
  'partnership',
  'other',
];

export type AccessStatus =
  'new' | 'contacted' | 'waiting_for_reply' | 'qualified' | 'approved' | 'rejected' | 'closed';

export type PreferredContact = 'email' | 'phone' | 'whatsapp';

export type ContactAction = 'email_sent' | 'call_attempted' | 'contacted' | 'whatsapp_contacted';

/** Where a request stands against the response time the workspace promised. `closed` is a request put away untried. */
export type SlaState = 'within' | 'approaching' | 'overdue' | 'attempted' | 'contacted' | 'closed';

export type AccessSla = { state: SlaState; due_at: string; met: boolean | null };

export type AccessRequestRow = {
  id: string;
  kind: AccessKind;
  name: string;
  email: string;
  company: string | null;
  phone: string | null;
  job_title: string | null;
  preferred_contact: PreferredContact;
  need: string | null;
  message: string | null;
  locale: string;
  status: AccessStatus;
  created_at: string;
  owner_id: string | null;
  owner_name: string | null;
  first_attempt_at: string | null;
  first_contacted_at: string | null;
  last_contact_at: string | null;
  next_follow_up_at: string | null;
  possible_duplicate: boolean;
  possible_duplicate_of: string | null;
  reviewed_at: string | null;
  reviewed_by_name: string | null;
  review_note: string | null;
  client_message: string | null;
  invited_at: string | null;
  invitation_shared_at: string | null;
  invitation_opened_at: string | null;
  accepted_at: string | null;
  account_created: boolean;
  first_login_at: string | null;
  sla: AccessSla | null;
};

export type AccessSettings = {
  response_minutes: number;
  default_owner_id: string | null;
  default_owner_name: string | null;
};

export type AccessOwner = { id: string; name: string };

export type DeliveryState = { state: 'awaiting_credentials' };

export type AccessInbox = {
  rows: AccessRequestRow[];
  counts: Record<AccessStatus, number>;
  settings: AccessSettings;
  owners: AccessOwner[];
  delivery: { email: DeliveryState; whatsapp: DeliveryState };
};

export type AccessEvent = {
  id: string;
  type: string;
  actor_name: string | null;
  body: string | null;
  meta: Record<string, unknown> | null;
  occurred_at: string;
};

export type LinkedRequest = {
  id: string;
  name: string;
  kind: AccessKind;
  status: AccessStatus;
  created_at: string;
};

export type InvitationSummary = {
  hint: string;
  role: string;
  project_ids: string[];
  expires_at: string;
  accepted_at: string | null;
  revoked_at: string | null;
  created_at: string;
};

export type AccessDetail = AccessRequestRow & {
  events: AccessEvent[];
  linked: LinkedRequest[];
  invitation: InvitationSummary | null;
};

export type AccessFigures = {
  days: number;
  new_requests: number;
  contacted: number;
  qualified: number;
  approved: number;
  rejected: number;
  median_first_response_seconds: number | null;
  sla_judged: number;
  sla_compliance: number | null;
  invitations_made: number;
  invitations_accepted: number;
  invitation_acceptance: number | null;
  response_minutes: number;
};

/** The roles an invitation may carry. Never the owner role: that is a decision for somebody already one. */
export const INVITABLE_ROLES = [
  'admin',
  'project-manager',
  'media-buyer',
  'creative',
  'client-viewer',
] as const;

export type InvitableRole = (typeof INVITABLE_ROLES)[number];

export type InvitationPreview =
  { valid: false } | { valid: true; name: string; email: string; needs_password: boolean };
