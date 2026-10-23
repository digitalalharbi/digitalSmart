import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type { AutomationSettings, FollowUp } from './types';

export function useFollowUpQuery(projectId: string | null) {
  return useQuery({
    queryKey: ['tracking', 'follow-up', projectId] as const,
    enabled: projectId !== null,
    refetchInterval: 60_000,
    queryFn: async (): Promise<FollowUp> =>
      (
        await api.get<{ data: FollowUp }>('/tracking/follow-up', {
          params: { project_id: projectId },
        })
      ).data.data,
  });
}

export function useSaveAutomationMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (settings: AutomationSettings): Promise<AutomationSettings> => {
      await ensureCsrfCookie();

      return (await api.put<{ data: AutomationSettings }>('/tracking/follow-up/settings', settings))
        .data.data;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['tracking', 'follow-up'] }),
  });
}
