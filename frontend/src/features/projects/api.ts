import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { PageMeta } from '@/shared/api/types';
import type {
  AssignableMember,
  Project,
  ProjectListParams,
  ProjectMember,
  ProjectPayload,
  ProjectStatus,
} from './types';

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

/**
 * Who is on a project.
 *
 * Project membership is the only thing in this product that grants sight of a client's data, so this list is
 * not a convenience: it is the answer to "who can see this client at all".
 */
export function useProjectMembersQuery(projectId: string | null) {
  return useQuery({
    queryKey: queryKeys.projects.members(projectId ?? ''),
    queryFn: async (): Promise<ProjectMember[]> => {
      const response = await api.get<{ data: ProjectMember[] }>(`/projects/${projectId}/members`);

      return response.data.data;
    },
    enabled: projectId !== null,
    staleTime: 30_000,
  });
}

/** Who could be given access: active workspace members who are not on it yet. */
export function useAssignableMembersQuery(projectId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.projects.assignable(projectId ?? ''),
    queryFn: async (): Promise<AssignableMember[]> => {
      const response = await api.get<{ data: AssignableMember[] }>(
        `/projects/${projectId}/assignable`,
      );

      return response.data.data;
    },
    enabled: enabled && projectId !== null,
    staleTime: 30_000,
  });
}

export function useAssignMemberMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      projectId: string;
      userId: string;
      role: string;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(`/projects/${input.projectId}/members`, {
        user_id: input.userId,
        role: input.role,
      });
    },
    /*
     * Everything under projects, because an assignment changes the member count on the row, both lists in the
     * panel, and what that person can see anywhere else in the product.
     */
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: queryKeys.projects.all() }),
  });
}

export function useRemoveMemberMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { projectId: string; userId: string }): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/projects/${input.projectId}/members/${input.userId}`);
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: queryKeys.projects.all() }),
  });
}
