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
  channel?: string;
  utm?: Record<string, string>;
  template_patterns?: Record<string, string>;
  notes?: string;
};

export type LinkTemplate = { id: string; name: string; patterns: Record<string, string> };
