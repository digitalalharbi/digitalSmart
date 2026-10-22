import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { fetchAdGroup, fetchAdGroups } from './api';
import type { AdGroupFilters } from './types';

/** Every filter that changes what the server is asked is in the key — one that is not returns the last answer. */
export function adGroupsKey(filters: AdGroupFilters) {
  return [
    'ad-groups',
    'list',
    {
      client: filters.client?.id ?? null,
      project: filters.project?.id ?? null,
      campaign: filters.campaign?.id ?? null,
      mine: filters.mine,
      days: filters.days,
      provider: filters.provider,
      status: filters.status,
      zeroResults: filters.zeroResults,
      search: filters.search,
      sort: filters.sort,
      descending: filters.descending,
      offset: filters.offset,
    },
  ] as const;
}

/** One page of the ad groups the reader may see, filtered and sorted by the server. */
export function useAdGroups(filters: AdGroupFilters) {
  return useQuery({
    queryKey: adGroupsKey(filters),
    queryFn: () => fetchAdGroups(filters),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}

export function useAdGroup(id: string, days: number | null) {
  return useQuery({
    queryKey: ['ad-groups', 'one', id, days] as const,
    queryFn: () => fetchAdGroup(id, days),
    enabled: id !== '',
    staleTime: 30_000,
  });
}
