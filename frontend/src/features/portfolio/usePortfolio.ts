import { useQuery } from '@tanstack/react-query';
import { useUiStore } from '@/shared/store/ui-store';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { Portfolio } from './types';

/** The agency, or — given a client — that client, from the same read. */
export function usePortfolio(clientId: string | null = null) {
  /* The shared period; the response's `window` says which dates it actually covered. */
  const days = useUiStore((state) => state.period.days);

  return useQuery({
    queryKey: [...queryKeys.portfolio.view(clientId), days],
    queryFn: async (): Promise<Portfolio> => {
      const { data } = await api.get<{ data: Portfolio }>(
        clientId === null ? '/portfolio' : `/portfolio/clients/${clientId}`,
        { params: { days } },
      );

      return data.data;
    },
    staleTime: 30_000,
  });
}
