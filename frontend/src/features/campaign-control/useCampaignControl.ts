import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { fetchCampaignControl } from './api';
import type { ControlFilters } from './types';

/**
 * One page of the agency's campaigns, filtered and sorted by the server.
 *
 * `keepPreviousData` so paging and filtering swap content rather than blanking the grid — at two thousand
 * campaigns the surface is mostly navigation, and a flash of skeleton on every click reads as slowness.
 */
export function useCampaignControl(filters: ControlFilters) {
  return useQuery({
    queryKey: queryKeys.campaignControl.list({
      client: filters.client?.id ?? null,
      project: filters.project?.id ?? null,
      provider: filters.provider,
      status: filters.status,
      objective: filters.objective,
      health: filters.health,
      search: filters.search,
      sort: filters.sort,
      descending: filters.descending,
      offset: filters.offset,
    }),
    queryFn: () => fetchCampaignControl(filters),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}
