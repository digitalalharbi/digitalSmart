export type Support = 'DOCUMENTED' | 'UNVERIFIED' | 'UNSUPPORTED';

export type ProviderEntry = {
  provider: string;
  label: string;
  support: Support;
  mechanism: string | null;
  events: string[] | null;
  event_rule: string | null;
  dedup: string | null;
  needs_click_id: string[];
  source: string | null;
  caveat: string | null;
  read_on: string | null;
};

export type Mapping = {
  id: string;
  event: string;
  provider: string;
  provider_event: string;
  enabled: boolean;
};

export type Dispatch = {
  id: string;
  event: string;
  provider: string;
  provider_event: string;
  event_id: string;
  channel: 'server' | 'offline';
  status: 'pending' | 'sent' | 'failed' | 'skipped' | 'unknown';
  skip_reason: string | null;
  source_state: string | null;
  attempts: number;
  error_category: string | null;
  occurred_at: string;
  server_sent_at: string | null;
  /** Null unless a provider's own response said so. */
  provider_received: boolean | null;
  deduplicated: boolean | null;
};

export type Conversions = {
  events: string[];
  providers: ProviderEntry[];
  mappings: Mapping[];
  policy: { consent_policy: 'required' | 'not_required'; retention_days: number };
  dispatches: Dispatch[];
};
