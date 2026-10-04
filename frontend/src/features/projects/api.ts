import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { PageMeta } from '@/shared/api/types';
import type { Project, ProjectListParams, ProjectPayload, ProjectStatus } from './types';

type ProjectPage = { data: Project[]; meta: PageMeta };

export function useProjectsQuery(params: ProjectListParams) {
  return useQuery({
    queryKey: queryKeys.projects.list(params),
    queryFn: async (): Promise<ProjectPage> => {
      const response = await api.get<ProjectPage>('/projects', {
        params: {
          search: params.search || undefined,
          status: params.status?.length ? params.status : undefined,
          client_id: params.client_id || undefined,
          page: params.page,
          per_page: params.per_page,
        },
      });
      return response.data;
    },
    placeholderData: (previous) => previous,
  });
}

function useInvalidateProjects() {
  const queryClient = useQueryClient();

  return () => queryClient.invalidateQueries({ queryKey: queryKeys.projects.all() });
}

export function useCreateProjectMutation() {
  const invalidate = useInvalidateProjects();

  return useMutation({
    mutationFn: async (payload: ProjectPayload): Promise<Project> => {
      await ensureCsrfCookie();
      const response = await api.post<{ data: Project }>('/projects', payload);
      return response.data.data;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useUpdateProjectMutation() {
  const invalidate = useInvalidateProjects();

  return useMutation({
    mutationFn: async ({
      id,
      payload,
    }: {
      id: string;
      payload: ProjectPayload;
    }): Promise<Project> => {
      await ensureCsrfCookie();
      const response = await api.put<{ data: Project }>(`/projects/${id}`, payload);
      return response.data.data;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useChangeProjectStatusMutation() {
  const invalidate = useInvalidateProjects();

  return useMutation({
    mutationFn: async ({ id, status }: { id: string; status: ProjectStatus }): Promise<Project> => {
      await ensureCsrfCookie();
      const response = await api.patch<{ data: Project }>(`/projects/${id}/status`, { status });
      return response.data.data;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useDeleteProjectMutation() {
  const invalidate = useInvalidateProjects();

  return useMutation({
    mutationFn: async (id: string): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/projects/${id}`);
    },
    onSuccess: () => void invalidate(),
  });
}
