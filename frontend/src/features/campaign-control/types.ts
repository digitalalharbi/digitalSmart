import type { HealthState } from '@/shared/ui/health.model';

export type HealthValue =
  'healthy' | 'watch' | 'needs_attention' | 'critical' | 'inactive' | 'insufficient_data';

/** The server's six words onto the shared presentation vocabulary. Same colour, glyph and meaning everywhere. */
export const HEALTH_TO_STATE: Record<HealthValue, HealthState> = {
  healthy: 'healthy',
  watch: 'watch',
  needs_attention: 'attention',
  critical: 'critical',
  inactive: 'inactive',
  insufficient_data: 'unknown',
};

export type CampaignRow = {
  id: string;
  name: string;
  provider: string;
  external_account: { id: string; name: string };
  client_id: string | null;
  client_name: string | null;
  project_id: string | null;
  project_name: string | null;
  ownership: 'assigned' | 'implied' | 'ambiguous' | 'unbound';
  ownership_label: string;
  status: string;
  status_label: string;
  /** The platform's own word. Shown beside the product's verdict, never instead of it. */
  provider_status: string | null;
  health: HealthValue;
  health_label: string;
  health_reason: string;
  needs_attention: boolean;
  objective: string;
  objective_label: string;
  currency: string | null;
  budget_minor: number | null;
  spend_minor: number | null;
  result: number | null;
  result_label: string;
  cost_per_result_minor: number | null;
  revenue_minor: number | null;
  roas: number | null;
  budget_utilisation: number | null;
  trend: Array<number | null>;
  reported_days: number;
  last_synced_at: string | null;
  /** Internal tags: the campaign's own plus its project's and client's. Empty for a role that may not see them. */
  tags: Array<{ id: string; name: string }>;
};

export type CampaignControlPage = {
  data: CampaignRow[];
  meta: {
    health_counts: Record<HealthValue, number>;
    total: number;
    matched: number;
    active_count: number;
    /** Over everything that matched, not the page — per currency, never added across them. */
    spend_by_currency: Array<{ currency: string; spend_minor: number }>;
    spend_trend: Array<{ currency: string; points: Array<number | null> }>;
    assessed: number;
    is_truncated: boolean;
    window: { from: string; to: string };
    limit: number;
    offset: number;
    health: Array<{ health: HealthValue; label: string; needs_attention: boolean; rank: number }>;
    statuses: Array<{ status: string; label: string }>;
    objectives: Array<{ objective: string; label: string; result_label: string }>;
  };
};

export type SortKey = 'health' | 'spend' | 'name' | 'cost' | 'roas';

/** Everything the page can be narrowed to — and everything that lives in the URL. */
export type ControlFilters = {
  client: { id: string; label: string } | null;
  project: { id: string; label: string } | null;
  account: { id: string; label: string } | null;
  /** Only the projects the reader is assigned to — the smallest truthful "owner": a project assignment. */
  mine: boolean;
  /** The window, in days. Null is the default the daily queue uses. */
  days: number | null;
  /** Summary shows the answer; detailed shows the machinery. */
  mode: 'summary' | 'detailed';
  provider: string | null;
  status: string | null;
  objective: string | null;
  health: HealthValue | null;
  tag: { id: string; label: string } | null;
  search: string;
  sort: SortKey;
  descending: boolean;
  offset: number;
  view: 'cards' | 'table';
};
