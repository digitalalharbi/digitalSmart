import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type { Conversions } from './types';

const KEY = ['tracking', 'conversions'] as const;

export function useConversionsQuery(projectId: string | null) {
  return useQuery({
    queryKey: [...KEY, projectId],
    enabled: projectId !== null,
    queryFn: async (): Promise<Conversions> =>
      (
        await api.get<{ data: Conversions }>('/tracking/conversions', {
          params: { project_id: projectId },
        })
      ).data.data,
  });
}

export function useSaveMappingMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      projectId: string;
      event: string;
      provider: string;
      providerEvent: string;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.post('/tracking/conversions/mappings', {
        project_id: input.projectId,
        event: input.event,
        provider: input.provider,
        provider_event: input.providerEvent,
      });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteMappingMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { projectId: string; id: string }): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/tracking/conversions/mappings/${input.id}`, {
        params: { project_id: input.projectId },
      });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useSavePolicyMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      projectId: string;
      consentPolicy: string;
      retentionDays: number;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.put('/tracking/settings/policy', {
        project_id: input.projectId,
        consent_policy: input.consentPolicy,
        retention_days: input.retentionDays,
      });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRetryDispatchMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(`/tracking/conversions/dispatches/${id}/retry`);
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}
