import { api } from '@/shared/api/client';
import type { CommerceQuery, StoreAnalytics } from './types';

export async function fetchStoreAnalytics(query: CommerceQuery): Promise<StoreAnalytics> {
  const { data } = await api.get<{ data: StoreAnalytics }>('/commerce/analytics', {
    params: {
      // Always sent. The server refuses a request without it, and so it should.
      revenue_source: query.revenue_source,
      ...(query.from === undefined ? {} : { from: query.from }),
      ...(query.until === undefined ? {} : { until: query.until }),
      ...(query.client_id == null ? {} : { client_id: query.client_id }),
      ...(query.limit === undefined ? {} : { limit: query.limit }),
    },
  });

  return data.data;
}
