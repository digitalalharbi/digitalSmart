import type { CostFigure, RevenueFigure, SpendBasis, BindingLevel } from '../links/types';

export type Money = {
  currency: string;
  spend_minor: number;
  priced_links: number;
  leads: number;
  qualified: number;
  appointments: number;
  sales: number;
  cpl_minor: number | null;
  cpql_minor: number | null;
  cac_minor: number | null;
  revenue: { lead_value_minor: number | null; store_attributed_minor: number | null };
  roas: { lead_value: number | null; store_attributed: number | null };
};

export type OpsStage = {
  source: string;
  count: number | null;
  conversion_from_previous: number | null;
  drop_off: number | null;
  median_seconds_from_previous: number | null;
  reason?: string | null;
};

export type OpsLink = {
  link: { id: string; name: string };
  platform: string | null;
  campaign: string | null;
  ad_group: string | null;
  ad: string | null;
  bound: boolean;
  spend: {
    basis: SpendBasis;
    minor: number | null;
    currency: string | null;
    reason: string | null;
    level: BindingLevel | null;
  };
  clicks: number;
  leads: number;
  qualified: number | null;
  appointments: number | null;
  sales: number | null;
  cpl: CostFigure;
  cpql: CostFigure;
  cac: CostFigure;
  revenue: Record<string, RevenueFigure>;
  sla: { leads: number; met: number | null; overdue: number | null } | null;
  tracking: 'ok' | 'page_not_reporting' | 'no_clicks';
};

export type TeamRow = {
  owner: { id: string; name: string | null } | null;
  assigned: number;
  contacted: number;
  qualified: number;
  median_attempt_seconds: number | null;
  median_contact_seconds: number | null;
  sla_compliance: number | null;
  overdue: number | null;
};

export type AttentionItem = {
  code: string;
  severity: string;
  subject: string | null;
  evidence: Record<string, number | string | null>;
};

export type Operations = {
  from: string;
  to: string;
  timezone: string;
  sla_minutes: number | null;
  links_considered: number;
  links_total: number;
  truncated: boolean;
  kpis: {
    tracked: {
      clicks: number;
      leads: number;
      qualified: number;
      appointments: number;
      sales: number;
    };
    by_currency: Money[];
    unpriced_links: number;
    response: {
      sla_minutes: number | null;
      leads: number;
      median_attempt_seconds: number | null;
      median_contact_seconds: number | null;
      sla_compliance: number | null;
      overdue: number | null;
    };
  };
  funnel: { scope: 'project' | 'chosen_links'; stages: OpsStage[] };
  links: OpsLink[];
  team: TeamRow[];
  attribution: {
    platform_reported: {
      clicks: number | null;
      results: number | null;
      entities: number;
      note: string;
    };
    first_known_touch: {
      leads_with_a_known_first_touch: number;
      same_as_last: number;
      different_from_last: number;
    };
    last_known_touch: { leads: number };
    digitalsmart_attributed: { leads: number };
    outcome: { sales: number; recorded_won_value_by_currency: Record<string, number> };
    coverage: number | null;
    confidence: 'none' | 'low' | 'medium' | 'high';
    missing_evidence: number;
  };
  attention: AttentionItem[];
};

export type OpsFilters = {
  platform: string;
  campaign_id: string;
  ad_group_id: string;
  ad_id: string;
  link_id: string;
  funnel_id: string;
};
