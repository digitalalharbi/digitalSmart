import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { queryKeys } from '@/shared/api/query-keys';
import { fetchAnalytics, fetchCatalogue } from './api';
import type { AnalyticsQuery, MetricDefinition } from '@/shared/metrics';

/**
 * The analytics query.
 *
 * `placeholderData: keepPreviousData` is the important option: changing a filter
 * keeps the previous figures on screen while the new ones load, so the page does
 * not collapse to skeletons on every interaction. The result is marked stale while
 * it refetches, which the UI dims rather than hides — a reader comparing two
 * filters should not lose their place.
 */
export function useAnalytics(query: AnalyticsQuery, enabled = true) {
  return useQuery({
    queryKey: queryKeys.analytics.query(query as Record<string, unknown>),
    queryFn: () => fetchAnalytics(query),
    enabled,
    // Performance data changes when a sync runs, not continuously. A minute is
    // long enough to make filter-flicking free and short enough that a finished
    // sync shows up without a reload.
    staleTime: 60_000,
    placeholderData: (previous) => previous,
  });
}

/**
 * The metric catalogue.
 *
 * Fetched rather than hard-coded so a metric's label, format and precision come
 * from one place. Effectively immutable within a session, hence the long stale
 * time.
 */
export function useMetricCatalogue(enabled = true) {
  const query = useQuery({
    queryKey: queryKeys.analytics.catalogue(),
    queryFn: fetchCatalogue,
    enabled,
    staleTime: 60 * 60_000,
  });

  const definitions = useMemo(() => {
    const map = new Map<string, MetricDefinition>();

    for (const metric of query.data?.metrics ?? []) {
      map.set(metric.value, metric);
    }

    return map;
  }, [query.data]);

  return { ...query, definitions };
}
