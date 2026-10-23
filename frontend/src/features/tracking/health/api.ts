import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type { DebugEvent, HealthReport, SnippetInfo } from './types';

export function useHealthQuery(projectId: string | null, linkId: string | null) {
  return useQuery({
    queryKey: ['tracking', 'health', projectId, linkId],
    enabled: projectId !== null,
    queryFn: async (): Promise<HealthReport> =>
      (
        await api.get<{ data: HealthReport }>('/tracking/health', {
          params: { project_id: projectId, ...(linkId === null ? {} : { link_id: linkId }) },
        })
      ).data.data,
  });
}

export function useStartTestMutation() {
  return useMutation({
    mutationFn: async (linkId: string): Promise<{ touchpoint: string; url: string }> => {
      await ensureCsrfCookie();

      return (
        await api.post<{ data: { touchpoint: string; url: string } }>(
          `/tracking/links/${linkId}/test`,
        )
      ).data.data;
    },
  });
}

/** Polls while a test is running: the console is a live view, so it asks again every two seconds. */
export function useDebugEventsQuery(
  projectId: string | null,
  touchpoint: string | null,
  live: boolean,
) {
  return useQuery({
    queryKey: ['tracking', 'debug', projectId, touchpoint],
    enabled: projectId !== null && touchpoint !== null,
    refetchInterval: live ? 2000 : false,
    queryFn: async (): Promise<DebugEvent[]> =>
      (
        await api.get<{ data: DebugEvent[] }>('/tracking/debug/events', {
          params: { project_id: projectId, touchpoint },
        })
      ).data.data,
  });
}

export function useSnippetQuery(projectId: string | null) {
  return useQuery({
    queryKey: ['tracking', 'snippet', projectId],
    enabled: projectId !== null,
    queryFn: async (): Promise<SnippetInfo> =>
      (
        await api.get<{ data: SnippetInfo }>('/tracking/snippet', {
          params: { project_id: projectId },
        })
      ).data.data,
  });
}

export function useSaveDataLayerMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      projectId: string;
      names: Record<string, string>;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.put('/tracking/settings/data-layer', {
        project_id: input.projectId,
        names: input.names,
      });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['tracking', 'snippet'] }),
  });
}
