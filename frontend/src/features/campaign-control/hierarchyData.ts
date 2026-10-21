import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';

export type Creative = {
  id: string;
  name: string;
  format: string;
  title: string | null;
  thumbnail_url: string | null;
};

export type Ad = {
  id: string;
  name: string;
  status: string;
  creative: Creative | null;
  spend_minor: number | null;
  ctr_now: number | null;
  ctr_change: number | null;
  frequency: number | null;
};

export type Group = {
  id: string;
  name: string;
  status: string;
  budget_mode: string;
  budget_minor: number | null;
  currency: string | null;
  audience: string | null;
  spend_minor: number | null;
  conversions: number | null;
  ads: Ad[];
};

export type Level = 'ad_group' | 'ad';
export type Change = 'pause' | 'resume' | 'budget';
export type Write = { support: 'documented' | 'not_offered'; because: string | null };

export type Hierarchy = {
  groups: Group[];
  creatives: Array<Creative & { ads: number; ad_names: string[] }>;
  /** What may be asked for at each level, on this campaign's own platform — never inferred from the campaign. */
  writes: Record<Level, Partial<Record<Change, Write>>>;
};

/**
 * A campaign's ad groups, ads and creatives, and what may be asked of each.
 *
 * One read, shared by the hierarchy tree and by the creative intelligence beside it: both see the same ads under the
 * same key, so a creative's figures and the tree row that names it cannot be from two different fetches.
 */
export function useCampaignHierarchy(campaignId: string, enabled: boolean) {
  return useQuery({
    queryKey: ['campaign-hierarchy', campaignId] as const,
    queryFn: async (): Promise<Hierarchy> => {
      const { data } = await api.get<{ data: Hierarchy }>(`/campaigns/${campaignId}/hierarchy`);

      return data.data;
    },
    enabled,
    staleTime: 60_000,
  });
}
