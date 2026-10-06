import { useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { fetchStoreAnalytics } from './api';
import type { CommerceQuery } from './types';

/**
 * What a client's stores sold, according to one named authority.
 *
 * Keyed by the whole query including the revenue source, because changing the source does not refine the
 * answer — it asks a different question. Caching the two together under one key would show a Salla total
 * while the label said GA4.
 */
export function useStoreAnalyticsQuery(query: CommerceQuery, enabled = true) {
  return useQuery({
    queryKey: queryKeys.commerce.analytics(query),
    queryFn: () => fetchStoreAnalytics(query),
    enabled,
    staleTime: 60_000,
  });
}
