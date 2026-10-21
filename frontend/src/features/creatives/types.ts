import type { MetricValue } from '@/shared/metrics';

export type CreativeMediaState = 'available' | 'fixture' | 'none';
export type CreativeVerdict = 'top' | 'rising' | 'stable' | 'fatiguing' | 'weak';
export type CreativeGrain = 'asset' | 'ad';

export type CreativeEvidence = { signal: string; value: number | string | null };

export type CreativeRow = {
  key: string;
  label: string;
  platform: string;
  platform_label: string;
  format: string;
  format_label: string;
  media: {
    state: CreativeMediaState;
    kind: 'image' | 'video' | 'none';
    url: string | null;
    origin: 'app' | 'provider' | null;
  };
  ads: number;
  campaigns: string[];
  campaign_count: number;
  ad_name: string | null;
  metrics: {
    source: string;
    source_label: string;
    currency: string | null;
    values: Record<string, MetricValue>;
  };
  trend: Array<{ date: string; spend: number | null; ctr: number | null }>;
  verdict: CreativeVerdict | null;
  verdict_label: string | null;
  evidence: CreativeEvidence[];
};

export type CreativeFacet = { key: string; label: string; count: number };

export type CreativePayload = {
  grain: CreativeGrain;
  range: { start: string; end: string; days: number };
  total: number;
  has_more: boolean;
  facets: { formats: CreativeFacet[]; platforms: CreativeFacet[] };
  rows: CreativeRow[];
};

export type CreativeQuery = {
  preset: '7d' | '14d' | '28d' | '30d' | '90d';
  grain?: CreativeGrain;
  client_id?: string;
  project_ids?: string[];
  campaign_ids?: string[];
  providers?: string[];
  format?: string;
  limit?: number;
};
