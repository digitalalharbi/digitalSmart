export type ProviderSlug =
  'meta' | 'google_ads' | 'tiktok' | 'snapchat' | 'x' | 'linkedin' | 'openai';

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
  auth_method: 'oauth2' | 'oauth1' | 'unknown';
  capabilities: Capability[];
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
