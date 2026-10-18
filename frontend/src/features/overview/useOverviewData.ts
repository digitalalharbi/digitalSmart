import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import type { CampaignControlPayload } from '@/features/campaign-control';

/**
 * What the command centre needs beyond the portfolio read: the daily spend line, from the campaign control read
 * (per currency, never added across them), and the latest changes across the agency.
 */
export function useSpendTrend() {
  return useQuery({
    queryKey: ['overview', 'spend-trend'] as const,
    queryFn: async (): Promise<CampaignControlPayload['meta']> => {
      const { data } = await api.get<CampaignControlPayload>('/campaigns/control', {
        params: { limit: 1, days: 28 },
      });

      return data.meta;
    },
    staleTime: 60_000,
  });
}

export type AgencyActivity = {
  id: string;
  sentence: string;
  actor: string | null;
  at: string;
};

export function useAgencyActivity() {
  return useQuery({
    queryKey: ['overview', 'activity'] as const,
    queryFn: async (): Promise<AgencyActivity[]> => {
      const { data } = await api.get<{ data: AgencyActivity[] }>('/activity', {
        params: { limit: 8 },
      });

      return data.data;
    },
    staleTime: 60_000,
    retry: false,
  });
}
