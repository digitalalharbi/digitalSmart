import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type { LinkDraft, LinkStatus, LinkTemplate, TrackingLink } from './types';

const KEY = ['tracking', 'links'] as const;

export function useLinksQuery(projectId: string | null) {
  return useQuery({
    queryKey: [...KEY, projectId],
    queryFn: async (): Promise<TrackingLink[]> => {
      const { data } = await api.get<{ data: TrackingLink[] }>('/tracking/links', {
        params: projectId === null ? {} : { project_id: projectId },
      });

      return data.data;
    },
  });
}

export function useLinkTemplatesQuery() {
  return useQuery({
    queryKey: ['tracking', 'link-templates'] as const,
    queryFn: async (): Promise<LinkTemplate[]> => {
      const { data } = await api.get<{ data: LinkTemplate[] }>('/tracking/link-templates');

      return data.data;
    },
  });
}

export function useSaveLinkMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { id?: string; draft: LinkDraft }): Promise<TrackingLink> => {
      await ensureCsrfCookie();
      const { data } =
        input.id === undefined
          ? await api.post<{ data: TrackingLink }>('/tracking/links', input.draft)
          : await api.put<{ data: TrackingLink }>(`/tracking/links/${input.id}`, input.draft);

      return data.data;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useSetLinkStatusMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { id: string; status: LinkStatus }): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(`/tracking/links/${input.id}/status`, { status: input.status });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}
