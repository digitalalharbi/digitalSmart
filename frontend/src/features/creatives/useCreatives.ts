import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { fetchCreatives } from './api';
import type { CreativeQuery } from './types';

/** Keeps the previous answer on screen while a filter is changing, so the page never collapses to skeletons. */
export function useCreatives(query: CreativeQuery, enabled = true) {
  return useQuery({
    queryKey: ['creatives', query] as const,
    queryFn: () => fetchCreatives(query),
    enabled,
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  });
}
