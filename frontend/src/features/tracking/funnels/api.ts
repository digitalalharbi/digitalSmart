import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type {
  BreakdownRow,
  Dimension,
  FollowUp,
  FunnelAnalytics,
  FunnelStageDef,
  LeadTimeline,
  StoredFunnel,
} from './types';

export type Period = { from: string; to: string };

const KEY = ['tracking', 'funnels'] as const;

export function useFunnelsQuery(projectId: string | null) {
  return useQuery({
    queryKey: [...KEY, 'list', projectId],
    enabled: projectId !== null,
    queryFn: async (): Promise<StoredFunnel[]> =>
      (
        await api.get<{ data: StoredFunnel[] }>('/tracking/funnels', {
          params: { project_id: projectId },
        })
      ).data.data,
  });
}

export function useFunnelAnalyticsQuery(
  projectId: string | null,
  funnelId: string | null,
  linkId: string | null,
  period: Period,
) {
  return useQuery({
    queryKey: [...KEY, 'analytics', projectId, funnelId, linkId, period],
    enabled: projectId !== null,
    queryFn: async (): Promise<FunnelAnalytics> =>
      (
        await api.get<{ data: FunnelAnalytics }>('/tracking/analytics/funnel', {
          params: {
            project_id: projectId,
            ...(funnelId === null ? {} : { funnel_id: funnelId }),
            ...(linkId === null ? {} : { link_id: linkId }),
            ...period,
          },
        })
      ).data.data,
  });
}

export function useBreakdownQuery(
  projectId: string | null,
  dimension: Dimension,
  linkId: string | null,
  period: Period,
) {
  return useQuery({
    queryKey: [...KEY, 'breakdown', projectId, dimension, linkId, period],
    enabled: projectId !== null,
    queryFn: async (): Promise<BreakdownRow[]> =>
      (
        await api.get<{ data: { rows: BreakdownRow[] } }>('/tracking/analytics/breakdown', {
          params: {
            project_id: projectId,
            dimension,
            ...(linkId === null ? {} : { link_id: linkId }),
            ...period,
          },
        })
      ).data.data.rows,
  });
}

export function useFollowUpQuery(projectId: string | null, period: Period) {
  return useQuery({
    queryKey: [...KEY, 'follow-up', projectId, period],
    enabled: projectId !== null,
    queryFn: async (): Promise<FollowUp> =>
      (
        await api.get<{ data: FollowUp }>('/tracking/analytics/follow-up', {
          params: { project_id: projectId, ...period },
        })
      ).data.data,
  });
}

export function useSaveFunnelMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      id?: string;
      projectId: string;
      name: string;
      stages: FunnelStageDef[];
    }): Promise<StoredFunnel> => {
      await ensureCsrfCookie();
      const body = { project_id: input.projectId, name: input.name, stages: input.stages };
      const { data } =
        input.id === undefined
          ? await api.post<{ data: StoredFunnel }>('/tracking/funnels', body)
          : await api.put<{ data: StoredFunnel }>(`/tracking/funnels/${input.id}`, body);

      return data.data;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteFunnelMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/tracking/funnels/${id}`);
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useLeadTimelineQuery(leadId: string | null) {
  return useQuery({
    queryKey: ['tracking', 'lead-timeline', leadId],
    enabled: leadId !== null,
    queryFn: async (): Promise<LeadTimeline> =>
      (await api.get<{ data: LeadTimeline }>(`/leads/${leadId}/timeline`)).data.data,
  });
}

export function useLeadMilestoneMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      leadId: string;
      type: 'appointment_booked' | 'opportunity_created';
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(`/leads/${input.leadId}/milestones`, { type: input.type });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['tracking'] }),
  });
}
