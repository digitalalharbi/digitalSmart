import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';

/** What the traffic endpoint returns. Users are deliberately not a field: they are not additive. */
export type Ga4Figures = {
  sessions: number | null;
  engaged_sessions: number | null;
  page_views: number | null;
  engagement_seconds: number | null;
  engagement_rate: number | null;
  engagement_seconds_per_session: number | null;
};

export type Ga4Row = Ga4Figures & {
  name: string;
  channel_group: string | null;
  source: string | null;
  medium: string | null;
  share_of_sessions: number | null;
};

export type Ga4Traffic = {
  period: { from: string; until: string };
  previous_period: { from: string; until: string };
  group: 'channel' | 'source_medium';
  totals: Ga4Figures;
  previous: Ga4Figures;
  change: { sessions: number | null; engaged_sessions: number | null; page_views: number | null };
  trend: Array<Ga4Figures & { date: string }>;
  rows: Ga4Row[];
  quality: {
    source: string | null;
    source_label: string | null;
    is_sandbox: boolean;
    time_zones: string[];
    days_in_period: number;
    days_present: number;
    first_day: string | null;
    last_day: string | null;
    is_complete: boolean;
    no_data: boolean;
  };
  sources: Array<{
    id: string;
    name: string;
    client_name: string;
    freshness: string;
    last_synced_at: string | null;
  }>;
  users: { available: boolean; reason: string };
};

export type Ga4TrafficResponse = {
  data: Ga4Traffic;
  meta: { page: number; per_page: number; total: number };
};

export type Ga4TrafficParams = {
  from: string;
  until: string;
  group: 'channel' | 'source_medium';
  page: number;
  perPage: 10 | 25 | 50;
  clientId: string | null;
};

export async function fetchGa4Traffic(params: Ga4TrafficParams): Promise<Ga4TrafficResponse> {
  const query = new URLSearchParams({
    from: params.from,
    until: params.until,
    group: params.group,
    page: String(params.page),
    per_page: String(params.perPage),
  });

  if (params.clientId !== null) {
    query.set('client_id', params.clientId);
  }

  const { data } = await api.get<Ga4TrafficResponse>(`/analytics/ga4/traffic?${query.toString()}`);

  return data;
}

/**
 * Stored GA4 traffic for a period. The previous page stays on screen while the next loads, so changing the grouping or
 * the page does not collapse the panel to a skeleton.
 */
export function useGa4Traffic(params: Ga4TrafficParams) {
  return useQuery({
    queryKey: ['analytics', 'ga4-traffic', params] as const,
    queryFn: () => fetchGa4Traffic(params),
    staleTime: 60_000,
    placeholderData: (previous) => previous,
  });
}

/** `YYYY-MM-DD` of a date in the reader's own calendar. */
function day(date: Date): string {
  const pad = (value: number) => String(value).padStart(2, '0');

  return `${String(date.getFullYear())}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The last `days` whole days, ending yesterday: today is still accumulating and a partial day reads as a drop. */
export function lastDays(days: number, now = new Date()): { from: string; until: string } {
  const until = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  const from = new Date(until.getFullYear(), until.getMonth(), until.getDate() - (days - 1));

  return { from: day(from), until: day(until) };
}

/** The GA4 e-commerce event funnel: aggregate event counts, never a path of people. */
export type Ga4Funnel = {
  period: { from: string; until: string };
  stages: Array<{ event: string; count: number | null; ratio_from_previous: number | null }>;
  days_present: number;
  days_in_period: number;
  source: string | null;
  time_zones: string[];
  sources: Array<{ id: string; name: string; client_name: string }>;
  note: string;
};

export async function fetchGa4Funnel(params: {
  from: string;
  until: string;
  clientId: string | null;
}): Promise<Ga4Funnel> {
  const query = new URLSearchParams({ from: params.from, until: params.until });

  if (params.clientId !== null) {
    query.set('client_id', params.clientId);
  }

  const { data } = await api.get<{ data: Ga4Funnel }>(`/analytics/ga4/funnel?${query.toString()}`);

  return data.data;
}

export function useGa4Funnel(params: { from: string; until: string; clientId: string | null }) {
  return useQuery({
    queryKey: ['analytics', 'ga4-funnel', params] as const,
    queryFn: () => fetchGa4Funnel(params),
    staleTime: 60_000,
    placeholderData: (previous) => previous,
  });
}
