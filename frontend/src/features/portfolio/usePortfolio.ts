import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { Portfolio } from './types';

/** The agency, or — given a client — that client, from the same read. */
export function usePortfolio(clientId: string | null = null) {
  return useQuery({
    queryKey: queryKeys.portfolio.view(clientId),
    queryFn: async (): Promise<Portfolio> => {
      const { data } = await api.get<{ data: Portfolio }>(
        clientId === null ? '/portfolio' : `/portfolio/clients/${clientId}`,
      );

      return data.data;
    },
    staleTime: 30_000,
  });
}
