import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';

export type OwnershipCandidate = { id: string; name: string };

export type OwnershipRow = {
  id: string;
  name: string;
  provider: string;
  external_account: { id: string; name: string };
  ownership: 'assigned' | 'implied' | 'ambiguous' | 'unbound';
  ownership_label: string;
  candidate_projects: OwnershipCandidate[];
};

export type OwnershipQueue = {
  data: OwnershipRow[];
  meta: { counts: Record<string, number> };
};

export type BulkAssignResult = {
  assigned: string[];
  refused: Array<{ campaign_id: string; reason: string }>;
};

/**
 * The campaigns nobody has placed: ambiguous (their account serves several projects) and unbound (it serves
 * none). Both counts are over the whole population; the rows are the first screenful.
 */
export function useOwnershipQueue() {
  return useQuery({
    queryKey: queryKeys.campaignControl.ownership(),
    queryFn: async (): Promise<OwnershipQueue> => {
      const { data } = await api.get<OwnershipQueue>('/campaigns/ownership', {
        params: { ownership: ['ambiguous', 'unbound'], limit: 25 },
      });

      return data;
    },
    staleTime: 30_000,
  });
}

export function useBulkAssign() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      campaignIds: string[];
      projectId: string;
    }): Promise<BulkAssignResult> => {
      await ensureCsrfCookie();
      const { data } = await api.post<{ data: BulkAssignResult }>('/campaigns/project', {
        campaign_ids: input.campaignIds,
        project_id: input.projectId,
      });

      return data.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.campaignControl.all() });
    },
  });
}
