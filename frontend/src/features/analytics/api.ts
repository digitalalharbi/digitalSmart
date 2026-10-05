import { api } from '@/shared/api/client';
import type { AnalyticsPayload, AnalyticsQuery, CataloguePayload } from '@/shared/metrics';

/** Turn the query object into the repeated-array form Laravel expects. */
function toParams(query: AnalyticsQuery): URLSearchParams {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') {
      continue;
    }

    if (Array.isArray(value)) {
      // `metrics[]=spend&metrics[]=roas`, which is what the FormRequest validates.
      for (const item of value) {
        params.append(`${key}[]`, String(item));
      }
      continue;
    }

    params.set(key, String(value));
  }

  return params;
}

export async function fetchAnalytics(query: AnalyticsQuery): Promise<AnalyticsPayload> {
  const { data } = await api.get<{ data: AnalyticsPayload }>(
    `/analytics?${toParams(query).toString()}`,
  );

  return data.data;
}

export async function fetchCatalogue(): Promise<CataloguePayload> {
  const { data } = await api.get<{ data: CataloguePayload }>('/analytics/catalogue');

  return data.data;
}
