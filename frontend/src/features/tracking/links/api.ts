import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type {
  BindingOption,
  LinkDraft,
  LinkPerformance,
  LinkStatus,
  LinkTemplate,
  TrackingLink,
} from './types';

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

/** One level of the campaign picker. Disabled until its parent is chosen, so a level is never offered without its context. */
export function useBindingOptions(
  projectId: string | null,
  level: 'accounts' | 'campaigns' | 'ad_groups' | 'ads',
  parentId: string | null,
  enabled: boolean,
) {
  return useQuery({
    queryKey: ['tracking', 'binding-options', projectId, level, parentId] as const,
    enabled: enabled && projectId !== null && (level === 'accounts' || parentId !== null),
    queryFn: async (): Promise<BindingOption[]> => {
      const { data } = await api.get<{ data: BindingOption[] }>('/tracking/binding-options', {
        params: {
          project_id: projectId,
          level,
          ...(parentId === null ? {} : { parent_id: parentId }),
        },
      });

      return data.data;
    },
  });
}

export function useLinkPerformanceQuery(
  linkId: string,
  days: number,
  allocate: boolean,
  enabled: boolean,
) {
  return useQuery({
    queryKey: ['tracking', 'link-performance', linkId, days, allocate] as const,
    enabled,
    queryFn: async (): Promise<LinkPerformance> => {
      const to = new Date();
      const from = new Date(to.getTime() - (days - 1) * 86_400_000);
      const day = (d: Date): string => d.toISOString().slice(0, 10);
      const { data } = await api.get<{ data: LinkPerformance }>(
        `/tracking/links/${linkId}/performance`,
        {
          params: { from: day(from), to: day(to), ...(allocate ? { allocate: 'clicks' } : {}) },
        },
      );

      return data.data;
    },
  });
}
