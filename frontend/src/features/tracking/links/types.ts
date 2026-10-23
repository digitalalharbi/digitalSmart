export type LinkStatus = 'active' | 'paused' | 'archived';

/** A figure and how it is known. A null value always has a reason: the page must never show a missing figure as zero. */
export type Counted = {
  value: number | null;
  state: 'live' | 'partial';
  reason?: string | null;
};

export type LinkCounts = {
  clicks: Counted;
  visitors: Counted;
  sessions: Counted;
  landing_views: Counted;
  click_id_coverage: number | null;
  excluded: number;
  last_click_at: string | null;
};

export type ConversionGoal = 'lead' | 'qualified' | 'appointment' | 'sale';

export type BindingLevel = 'campaign' | 'ad_group' | 'ad';

/** Where a link sits in the campaign hierarchy; every field null for a link that is not bound. */
export type LinkBinding = {
  external_account_id: string | null;
  campaign_id: string | null;
  ad_group_id: string | null;
  ad_id: string | null;
  creative_id: string | null;
  level: BindingLevel | null;
};

export type BindingOption = { id: string; name: string; detail: string | null };

export type SpendBasis = 'DIRECT' | 'ALLOCATED' | 'UNAVAILABLE';

/** A cost: a figure in minor units, or null with the reason there is none. Never shown as zero. */
export type CostFigure = { minor: number | null; currency: string | null; reason: string | null };

export type RevenueFigure = {
  minor: number | null;
  currency: string | null;
  count: number | null;
  reason: string | null;
  evidence: string;
  roas: { value: number | null; reason: string | null };
};

export type LinkPerformance = {
  link_id: string;
  from: string;
  to: string;
  binding_level: BindingLevel | null;
  funnel: {
    stages: {
      source: string;
      count: number | null;
      conversion_from_previous: number | null;
      median_seconds_from_previous: number | null;
    }[];
  };
  spend: {
    basis: SpendBasis;
    minor: number | null;
    currency: string | null;
    level: BindingLevel | null;
    reason: string | null;
    days_reported: number | null;
    allocation: {
      method: string;
      share: number;
      coverage: number | null;
      confidence: string;
    } | null;
  };
  costs: Record<string, CostFigure>;
  revenue: Record<string, RevenueFigure>;
};

export type TrackingLink = {
  id: string;
  client: { id: string; name: string };
  project: { id: string; name: string };
  name: string;
  short_url: string;
  platform: string | null;
  campaign: string | null;
  ad_group: string | null;
  ad: string | null;
  creative: string | null;
  binding: LinkBinding;
  conversion_goal: ConversionGoal | null;
  channel: string;
  destination_url: string;
  utm: Record<string, string>;
  custom_params: Record<string, string>;
  objective: string | null;
  status: LinkStatus;
  expires_at: string | null;
  notes: string | null;
  created_at: string;
  counts: LinkCounts | null;
};

export type LinkDraft = {
  project_id: string;
  name: string;
  destination_url: string;
  platform?: string;
  campaign?: string;
  ad_group?: string;
  ad?: string;
  creative?: string;
  external_account_id?: string;
  campaign_id?: string;
  ad_group_id?: string;
  ad_id?: string;
  conversion_goal?: ConversionGoal;
  channel?: string;
  utm?: Record<string, string>;
  template_patterns?: Record<string, string>;
  notes?: string;
};

export type LinkTemplate = { id: string; name: string; patterns: Record<string, string> };
