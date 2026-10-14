/**
 * Every provider in the registry, in its canonical slug form.
 *
 * Mirrors the backend's `Provider` enum. The *labels* are never written here: they come
 * from the registry on every response, so a page cannot drift from what a platform is
 * actually called.
 */
export type ProviderSlug =
  | 'meta'
  | 'google_ads'
  | 'tiktok'
  | 'snapchat'
  | 'x'
  | 'linkedin'
  | 'openai'
  | 'google_analytics'
  | 'google_tag_manager'
  | 'salla'
  | 'zid'
  | 'hubspot';

export type ProviderCategorySlug =
  | 'advertising'
  | 'analytics'
  | 'tag_management'
  | 'commerce'
  /*
   * Its own category, and load-bearing rather than cosmetic: figures never total across categories, and a CRM's
   * deal value is not a store's invoiced revenue nor a platform's claimed one.
   */
  | 'crm';

/** How reachable a provider's API is in principle — not whether this workspace connected it. */
export type ProviderApiStatus = 'generally_available' | 'requires_approval' | 'unavailable';

export type ProviderEntityRow = {
  slug: string;
  label: string;
};

/**
 * What the provider published about how often it may be called.
 *
 * Null where the provider documents nothing. Never filled in with a guess: a ceiling
 * nobody set is a ceiling nobody can check.
 */
export type ProviderRateLimit = {
  requests: number | null;
  window_seconds: number | null;
  scope: string | null;
  daily_requests: number | null;
  note: string | null;
};

export type ConnectionStatus =
  'not_configured' | 'configured' | 'connected' | 'expired' | 'revoked' | 'error';

export type Capability = {
  slug: string;
  label: string;
  /** Changes something on the provider; gated behind explicit approval. */
  is_write: boolean;
};

export type ProviderRow = {
  provider: ProviderSlug;
  label: string;
  category: ProviderCategorySlug;
  category_label: string;
  /*
   * Separate from `blocked_external`, which says a workspace cannot proceed. This says
   * why: a programme to be admitted to, an app to register, or no public API at all.
   */
  api_status: ProviderApiStatus;
  api_status_label: string;
  auth_method: 'oauth2' | 'oauth1' | 'unknown';
  capabilities: Capability[];
  entities: ProviderEntityRow[];
  rate_limit: ProviderRateLimit | null;
  documentation_url: string | null;
  connection_id: string | null;
  status: ConnectionStatus;
  status_label: string;
  can_sync: boolean;
  needs_attention: boolean;
  status_message: string | null;
  /** Credential keys still needed, by name. Never values. */
  missing_credentials: string[];
  /** Waiting on something only the account owner can obtain. */
  blocked_external: boolean;
  /**
   * The stored credential is this repository's literal `sandbox` token.
   *
   * Published because "Connected" read the same for a fixture-backed connection and a client's real one, and
   * this is the page where somebody decides a provider is ready.
   */
  is_sandbox: boolean;
  account_count: number;
  last_checked_at: string | null;
  expires_at: string | null;
};

export type ExternalAccount = {
  id: string;
  provider: ProviderSlug;
  provider_label: string;
  external_id: string;
  name: string;
  currency: string | null;
  timezone: string | null;
  status: 'active' | 'disabled' | 'closed' | 'unsettled' | 'unknown';
  is_bindable: boolean;
  /** How many projects currently read this account. */
  binding_count: number;
  discovered_at: string | null;
};

export type ProjectBinding = {
  id: string;
  project_id: string;
  external_account: {
    id: string;
    name: string;
    provider: ProviderSlug;
    provider_label: string;
  };
  sync_enabled: boolean;
  bound_at: string | null;
  last_synced_at: string | null;
};
