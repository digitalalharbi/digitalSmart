import type { HealthValue } from '@/features/campaign-control';

export type AdGroupRow = {
  id: string;
  name: string;
  provider: string;
  campaign: { id: string; name: string };
  client_id: string | null;
  client_name: string | null;
  project_id: string | null;
  project_name: string | null;
  status: string;
  status_label: string;
  objective: string;
  objective_label: string;
  currency: string | null;
  budget_minor: number | null;
  budget_utilisation: number | null;
  spend_minor: number | null;
  result: number | null;
  result_label: string;
  cost_per_result_minor: number | null;
  revenue_minor: number | null;
  roas: number | null;
  impressions: number | null;
  clicks: number | null;
  /** Clicks over impressions; null where nothing was reported to divide by. */
  ctr: number | null;
  starts_on: string | null;
  ends_on: string | null;
  reported_days: number;
  /**
   * The parent campaign's verdict, named as the campaign's. The product judges health once, about campaigns; an ad
   * group has no verdict of its own and is never given one by borrowing the campaign's policy over its own figures.
   */
  campaign_health: {
    health: HealthValue;
    label: string;
    reason: string;
    reason_code: string;
    reason_params: Record<string, string | number>;
  } | null;
};

export type AdGroupPage = {
  data: AdGroupRow[];
  meta: {
    matched: number;
    is_truncated: boolean;
    days: number;
    limit: number;
    offset: number;
    spend_by_currency: Array<{ currency: string; spend_minor: number }>;
    statuses: Array<{ status: string; label: string }>;
  };
};

export type AdGroupSort = 'spend' | 'name' | 'cost' | 'roas' | 'ctr';

/** Everything the page can be narrowed to — and everything that lives in the URL. */
export type AdGroupFilters = {
  client: { id: string; label: string } | null;
  project: { id: string; label: string } | null;
  campaign: { id: string; label: string } | null;
  mine: boolean;
  days: number | null;
  provider: string | null;
  status: string | null;
  zeroResults: boolean;
  search: string;
  sort: AdGroupSort;
  descending: boolean;
  offset: number;
};
