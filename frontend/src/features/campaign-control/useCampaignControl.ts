import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { fetchCampaignControl } from './api';
import type { ControlFilters } from './types';

/** Every filter that changes what the server is asked is in the key — one that is not returns the last answer. */
export function campaignControlKey(filters: ControlFilters) {
  return queryKeys.campaignControl.list({
    client: filters.client?.id ?? null,
    project: filters.project?.id ?? null,
    account: filters.account?.id ?? null,
    mine: filters.mine,
    days: filters.days,
    provider: filters.provider,
    status: filters.status,
    objective: filters.objective,
    health: filters.health,
    tag: filters.tag?.id ?? null,
    endsWithin: filters.endsWithin,
    budget: filters.budget,
    zeroResults: filters.zeroResults,
    search: filters.search,
    sort: filters.sort,
    descending: filters.descending,
    offset: filters.offset,
  });
}

/**
 * One page of the agency's campaigns, filtered and sorted by the server.
 *
 * `keepPreviousData` so paging and filtering swap content rather than blanking the grid — at two thousand
 * campaigns the surface is mostly navigation, and a flash of skeleton on every click reads as slowness.
 */
export function useCampaignControl(filters: ControlFilters) {
  return useQuery({
    queryKey: campaignControlKey(filters),
    queryFn: () => fetchCampaignControl(filters),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}
