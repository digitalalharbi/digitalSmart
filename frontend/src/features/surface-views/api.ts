import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { SurfaceFilters, SurfaceKind, SurfaceView, ViewVisibility } from './types';

export function useSurfaceViews(surface: SurfaceKind, enabled = true) {
  return useQuery({
    queryKey: queryKeys.surfaceViews.list(surface),
    queryFn: async (): Promise<SurfaceView[]> => {
      const { data } = await api.get<{ data: SurfaceView[] }>(`/views/${surface}`);

      return data.data;
    },
    enabled,
    staleTime: 60_000,
  });
}

export function useSaveSurfaceView(surface: SurfaceKind) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      name: string;
      visibility: ViewVisibility;
      projectId: string | null;
      filters: SurfaceFilters;
    }): Promise<SurfaceView> => {
      await ensureCsrfCookie();
      const { data } = await api.post<{ data: SurfaceView }>(`/views/${surface}`, {
        name: input.name,
        visibility: input.visibility,
        ...(input.projectId === null ? {} : { project_id: input.projectId }),
        filters: input.filters,
      });

      return data.data;
    },
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: queryKeys.surfaceViews.list(surface) }),
  });
}

export function useDeleteSurfaceView(surface: SurfaceKind) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/views/${id}`);
    },
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: queryKeys.surfaceViews.list(surface) }),
  });
}
