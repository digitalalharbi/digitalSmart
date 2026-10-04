export const PROJECT_STATUSES = ['planning', 'active', 'paused', 'completed', 'archived'] as const;

export type ProjectStatus = (typeof PROJECT_STATUSES)[number];

export type Project = {
  id: string;
  client: { id: string; name: string };
  name: string;
  slug: string;
  status: ProjectStatus;
  currency: string;
  description: string | null;
  starts_on: string | null;
  ends_on: string | null;
  is_demo: boolean;
  member_count: number;
  /** Whether the viewer is explicitly assigned to this project. */
  is_assigned: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type ProjectListParams = {
  search?: string;
  status?: ProjectStatus[];
  client_id?: string;
  page?: number;
  per_page?: number;
};

export type ProjectPayload = {
  client_id?: string;
  name: string;
  currency: string;
  description?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
};

/** Mirrors the domain's transitions; the server remains the authority. */
export const ALLOWED_TRANSITIONS: Record<ProjectStatus, ProjectStatus[]> = {
  planning: ['active', 'archived'],
  active: ['paused', 'completed', 'archived'],
  paused: ['active', 'completed', 'archived'],
  completed: ['active', 'archived'],
  archived: [],
};

/** Statuses during which campaigns may spend. Shown so pacing is never a surprise. */
export const SPENDING_STATUSES: ProjectStatus[] = ['active'];
