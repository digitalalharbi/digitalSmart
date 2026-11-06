import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';

export type Influencer = {
  id: string;
  client: { id: string; name: string };
  name: string;
  handle: string | null;
  platform: string | null;
  notes: string | null;
  status: 'active' | 'archived';
  created_at: string;
  /** Valid traffic only, over this influencer's links. No fee and no cost: neither is recorded. */
  totals: { links: number; clicks: number; visitors: number };
};

export type InfluencerDraft = {
  client_id: string;
  name: string;
  handle?: string;
  platform?: string;
  notes?: string;
  status?: 'active' | 'archived';
};

const KEY = ['tracking', 'influencers'] as const;

/** A client's influencers, or every one the reader may see. Disabled until asked for. */
export function useInfluencersQuery(clientId: string | null, enabled = true) {
  return useQuery({
    queryKey: [...KEY, clientId],
    enabled,
    queryFn: async (): Promise<Influencer[]> => {
      const { data } = await api.get<{ data: Influencer[] }>('/influencers', {
        params: clientId === null ? {} : { client_id: clientId },
      });

      return data.data;
    },
  });
}

export function useSaveInfluencerMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { id?: string; draft: InfluencerDraft }): Promise<Influencer> => {
      await ensureCsrfCookie();
      const { data } =
        input.id === undefined
          ? await api.post<{ data: Influencer }>('/influencers', input.draft)
          : await api.put<{ data: Influencer }>(`/influencers/${input.id}`, input.draft);

      return data.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY });
      /* A link names its influencer, so the list of links is read again too. */
      void queryClient.invalidateQueries({ queryKey: ['tracking', 'links'] });
    },
  });
}
