import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { SearchHit, SearchHitType } from './types';

/**
 * Agency search, one request for every entity type.
 *
 * Enabled only from two characters: a single letter matches most of an agency and returns the five
 * arbitrary rows per type that sort first, which reads as a result and is not one.
 */
export function useSearchQuery(term: string, types: SearchHitType[] = []) {
  const trimmed = term.trim();

  return useQuery({
    queryKey: queryKeys.search.query({ term: trimmed, types }),
    queryFn: async (): Promise<SearchHit[]> => {
      const { data } = await api.get<{ data: SearchHit[] }>('/search', {
        params: { q: trimmed, ...(types.length === 0 ? {} : { type: types }) },
      });

      return data.data;
    },
    enabled: trimmed.length >= 2,
    staleTime: 30_000,
  });
}
