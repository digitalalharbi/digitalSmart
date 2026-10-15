import { useQuery } from '@tanstack/react-query';
import type { CampaignControlPayload } from '@/features/campaign-control';
import { api } from '@/shared/api/client';

/** What a person's own campaigns look like, from the control center's endpoint and no other. */
export function useMyCampaignMeta() {
  return useQuery({
    queryKey: ['home', 'my-campaign-meta'] as const,
    queryFn: async (): Promise<CampaignControlPayload['meta']> => {
      const { data } = await api.get<CampaignControlPayload>('/campaigns/control', {
        params: { mine: 1, limit: 1 },
      });

      return data.meta;
    },
    staleTime: 30_000,
  });
}

export type BudgetMeta = {
  meta: {
    totals: Array<{
      currency: string;
      approved_minor: number;
      allocated_minor: number;
      spent_minor: number;
      unreported: number;
    }>;
  };
  data: Array<{
    id: string;
    name: string;
    client: { id: string; name: string };
    currency: string;
    approved_minor: number;
    spent_minor: number | null;
    risks: Array<{ risk: string; label: string }>;
  }>;
};

export function useBudgetOverview() {
  return useQuery({
    queryKey: ['home', 'budget-overview'] as const,
    queryFn: async (): Promise<BudgetMeta> => {
      const { data } = await api.get<BudgetMeta>('/budgets/control');

      return data;
    },
    staleTime: 30_000,
  });
}
