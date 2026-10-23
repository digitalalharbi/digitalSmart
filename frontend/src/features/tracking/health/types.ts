export type HealthState =
  | 'LIVE'
  | 'SYNCED'
  | 'STALE'
  | 'PARTIAL'
  | 'SANDBOX'
  | 'MANUAL'
  | 'ESTIMATED'
  | 'ATTRIBUTED'
  | 'NOT_CONFIGURED'
  | 'AWAITING_CREDENTIALS'
  | 'UNSUPPORTED';

export type HealthCheck = {
  key: string;
  group: 'source' | 'destination' | 'browser' | 'server' | 'quality';
  state: HealthState;
  needs_attention: boolean;
  detail: string;
  evidence: Record<string, string | number | null>;
};

export type HealthReport = {
  checks: HealthCheck[];
  summary: { needs_attention: number; by_state: Record<string, number> };
  scope: 'project' | 'link';
};

export type DebugEvent = {
  id: string;
  at: string;
  recorded_at: string;
  event: string;
  source: 'first_party' | 'server' | 'test';
  status: 'valid' | 'suspected_bot' | 'duplicate' | 'test';
  event_id: string;
  consent: string;
  destinations: { state: HealthState; detail: string };
};

export type SnippetInfo = {
  data_layer: Record<string, string>;
  defaults: Record<string, string>;
  snippet: string;
};
