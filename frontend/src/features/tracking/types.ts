import type { MetricSourceValue } from '@/shared/metrics';

/**
 * The measurement API's payloads, as the server publishes them.
 *
 * Snake_case on purpose: these are the wire shapes, and renaming them on arrival would mean
 * every field existing under two names with a mapping to keep honest between them.
 */

export type MeasurementSourceKind =
  'analytics' | 'tag_manager' | 'ad_platform' | 'store' | 'crm' | 'website' | 'manual';

/**
 * How current a source is.
 *
 * `stale` and `silent` are deliberately distinct. Stale means the figures are old; silent means
 * the source is syncing perfectly and measuring nothing — which is the failure the tracking page
 * exists to catch, because the reports built on it come out quietly empty rather than visibly
 * broken.
 */
export type Freshness = 'live' | 'synced' | 'stale' | 'silent' | 'never_synced';

export type TrackingSeverity = 'info' | 'warning' | 'critical';

export type MeasurementSource = {
  id: string;
  client_id: string;
  client_name: string;
  project_id: string | null;
  project_name: string | null;
  provider: string | null;
  provider_label: string | null;
  kind: MeasurementSourceKind;
  kind_label: string;
  external_id: string;
  name: string;
  freshness: Freshness;
  freshness_label: string;
  needs_attention: boolean;
  /** True only for a store or a CRM. An analytics property is not an invoice. */
  is_revenue_authority: boolean;
  is_active: boolean;
  last_synced_at: string | null;
  last_event_at: string | null;
};

export type EventMapping = {
  id: string;
  raw_event_name: string;
  /** Null means "from any source" — a different statement from "a source we cannot name". */
  measurement_source_id: string | null;
  measurement_source_name: string | null;
};

export type EventDefinition = {
  id: string;
  client_id: string;
  project_id: string | null;
  name: string;
  normalized_event: string;
  normalized_event_label: string;
  is_conversion: boolean;
  is_primary: boolean;
  expects_value: boolean;
  /** A click is not a conversation; a form start is not a submission. */
  is_intent_signal_only: boolean;
  description: string | null;
  mappings: EventMapping[];
  /** Null when no window was asked for; 0 means asked, and never seen. */
  observed_count: number | null;
  last_seen_date: string | null;
};

/** One thing wrong with a client's measurement, with the facts it rests on. */
export type TrackingIssue = {
  code: string;
  severity: TrackingSeverity;
  severity_label: string;
  summary: string;
  remedy: string | null;
  subject: string | null;
  evidence: Record<string, unknown>;
};

export type TrackingHealth = {
  is_healthy: boolean;
  critical_count: number;
  window_days: number;
  sources: MeasurementSource[];
  definitions: EventDefinition[];
  issues: TrackingIssue[];
};

export type DataStream = {
  stream_id: string;
  display_name: string;
  type: string;
  measurement_id: string | null;
  default_uri: string | null;
  app_identifier: string | null;
  is_web: boolean;
};

export type DiscoveredProperty = {
  property_id: string;
  display_name: string;
  account_name: string;
  account_display_name: string;
  property_type: string | null;
  /** A roll-up counts the same events its sources do, so measuring both double-counts. */
  is_aggregate: boolean;
  streams: DataStream[];
};

export type ConversionSuggestion = {
  raw_event_name: string;
  /** Null means the product will not place this name, and a person must choose. */
  normalized_event: string | null;
  normalized_event_label: string | null;
  is_intent_signal_only: boolean | null;
  already_mapped: boolean;
  is_custom: boolean;
  counted_once_per_session: boolean;
  default_value: string | null;
  default_currency: string | null;
};

export type SyncResult = {
  status: 'succeeded' | 'partial' | 'failed' | 'skipped';
  days_read: number;
  rows_written: number;
  api_calls: number;
  source: MetricSourceValue;
  source_label: string;
  /** Why the figures are not totals. One sentence per cause. */
  caveats: string[];
  last_event_date: string | null;
  failure_message: string | null;
};

export type TagContainer = {
  container_id: string;
  path: string;
  name: string;
  /** `GTM-XXXXXX`: the only id that proves a container is the right one. */
  public_id: string;
  account_id: string;
  usage_contexts: string[];
  is_web: boolean;
  domain_names: string[];
  tag_ids: string[];
  tag_manager_url: string | null;
};

export type ContainerReadiness = {
  container: TagContainer;
  is_ready: boolean;
  /** Published state, not workspace state. */
  has_live_version: boolean;
  live_version_id: string | null;
  has_unpublished_changes: boolean;
  published_tag_count: number;
  critical_count: number;
  measurement_ids_found: string[];
  measurement_ids_expected: string[];
  issues: TrackingIssue[];
};

/** What a person fills in to register a source. */
export type SourceDraft = {
  client_id: string;
  project_id: string | null;
  kind: MeasurementSourceKind;
  provider: string | null;
  external_id: string;
  name: string;
};

/** What a person fills in to define a conversion. */
export type ConversionDraft = {
  client_id: string;
  project_id: string | null;
  name: string;
  normalized_event: string;
  is_conversion: boolean;
  is_primary: boolean;
  expects_value: boolean;
  mappings: Array<{ raw_event_name: string; measurement_source_id: string | null }>;
};

export type TrackingScope = { client_id?: string | null; project_id?: string | null };
