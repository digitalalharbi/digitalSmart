import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  createDashboard,
  deleteDashboard,
  duplicateDashboard,
  fetchDashboard,
  fetchDashboards,
  updateDashboard,
} from './api';
import type { DashboardDraft, DashboardPayload } from './types';

export function useDashboardsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.dashboards.list(),
    queryFn: fetchDashboards,
    enabled,
    staleTime: 30_000,
  });
}

export function useDashboardQuery(id: string | null) {
  return useQuery({
    queryKey: queryKeys.dashboards.detail(id ?? 'none'),
    queryFn: () => fetchDashboard(id ?? ''),
    enabled: id !== null,
    staleTime: 30_000,
  });
}

/**
 * Save a dashboard, creating or replacing.
 *
 * The list and the detail are both invalidated: a rename changes the picker, and a
 * layout change changes the canvas. Invalidating one and not the other is how a
 * reader sees a stale name beside fresh widgets.
 */
export function useSaveDashboard() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, draft }: { id: string | null; draft: DashboardDraft }) =>
      id === null ? createDashboard(draft) : updateDashboard(id, draft),
    onSuccess: (dashboard: DashboardPayload | undefined) => {
      if (dashboard !== undefined) {
        queryClient.setQueryData(queryKeys.dashboards.detail(dashboard.id), dashboard);
      }

      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboards.all() });
    },
  });
}

export function useDeleteDashboard() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteDashboard(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboards.all() });
    },
  });
}

export function useDuplicateDashboard() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, name }: { id: string; name?: string }) => duplicateDashboard(id, name),
    onSuccess: (dashboard: DashboardPayload) => {
      queryClient.setQueryData(queryKeys.dashboards.detail(dashboard.id), dashboard);
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboards.all() });
    },
  });
}
