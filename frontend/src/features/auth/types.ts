/** Mirrors the backend contract of GET /api/auth/me (see docs/API.md). */
export type WorkspaceSummary = {
  id: string;
  name: string;
  slug: string;
  role: string;
  role_name: string;
  default_currency: string;
  default_locale: string;
  timezone: string;
};

export type AuthenticatedUser = {
  id: string;
  name: string;
  email: string;
  locale: string;
  timezone: string;
  job_title: string | null;
  avatar_url: string | null;
  workspaces: WorkspaceSummary[];
  active_workspace: WorkspaceSummary | null;
  /** Effective permission slugs. The server enforces them; the UI only hides. */
  permissions: string[];
};

export type Credentials = {
  email: string;
  password: string;
  remember?: boolean;
};
