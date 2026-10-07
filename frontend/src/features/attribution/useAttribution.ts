import { useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { fetchAttribution, fetchAttributionModels, fetchComparison } from './api';
import type { AttributionParams, ComparisonParams } from './types';

/**
 * The model catalogue.
 *
 * Held for a long time: it changes when this product gains the data to compute another model, which
 * is a deployment rather than an event. Fetched at all — rather than hard-coded — because the
 * interface must not carry attribution vocabulary the server does not agree with.
 */
export function useAttributionModelsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.attribution.models(),
    queryFn: fetchAttributionModels,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

export function useAttributionQuery(query: AttributionParams, enabled = true) {
  return useQuery({
    queryKey: queryKeys.attribution.answer(query),
    queryFn: () => fetchAttribution(query),
    enabled,
    staleTime: 60_000,
  });
}

/**
 * Every computable model over one period.
 *
 * More expensive than one model and worth it: the disagreement between them is the only honest
 * headline this surface has, and computing it in the browser from separate requests would let two
 * views of the page disagree about the same campaign.
 */
export function useComparisonQuery(query: ComparisonParams, enabled = true) {
  return useQuery({
    queryKey: queryKeys.attribution.comparison(query),
    queryFn: () => fetchComparison(query),
    enabled,
    staleTime: 60_000,
  });
}
