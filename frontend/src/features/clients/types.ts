export const CLIENT_STATUSES = [
  'prospect',
  'onboarding',
  'active',
  'paused',
  'offboarded',
  'archived',
] as const;

export type ClientStatus = (typeof CLIENT_STATUSES)[number];

export type Client = {
  id: string;
  name: string;
  slug: string;
  status: ClientStatus;
  legal_name: string | null;
  industry: string | null;
  website: string | null;
  primary_contact_name: string | null;
  primary_contact_email: string | null;
  primary_contact_phone: string | null;
  default_currency: string | null;
  notes: string | null;
  is_demo: boolean;
  project_count: number;
  created_at: string | null;
  updated_at: string | null;
};

export type ClientListParams = {
  search?: string;
  status?: ClientStatus[];
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
};

export type ClientPayload = {
  name: string;
  legal_name?: string | null;
  industry?: string | null;
  website?: string | null;
  primary_contact_name?: string | null;
  primary_contact_email?: string | null;
  primary_contact_phone?: string | null;
  default_currency?: string | null;
  notes?: string | null;
};

/**
 * Which status changes the backend allows.
 *
 * Mirrored here so the UI offers only legal transitions; the domain remains
 * the authority and rejects anything else with invalid_status_transition.
 */
export const ALLOWED_TRANSITIONS: Record<ClientStatus, ClientStatus[]> = {
  prospect: ['onboarding', 'active', 'archived'],
  onboarding: ['active', 'archived'],
  active: ['paused', 'offboarded', 'archived'],
  paused: ['active', 'offboarded', 'archived'],
  offboarded: ['active', 'archived'],
  archived: [],
};
