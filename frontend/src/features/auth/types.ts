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
  /**
   * True while this person is still using a password somebody else chose for them.
   *
   * Acted on rather than displayed: the server refuses every request except reading this session, changing the
   * password and signing out, so an interface that ignored it would show a wall of refusals instead of the one
   * thing the reader can do.
   */
  must_choose_password: boolean;
};

export type Credentials = {
  email: string;
  password: string;
  remember?: boolean;
};

/**
 * A password change.
 *
 * The current password is part of the request rather than assumed from the session: a session can be left open
 * on a machine that is not yours, and without the check whoever found it could lock the owner out.
 */
export type PasswordChange = {
  current_password: string;
  password: string;
  password_confirmation: string;
};
