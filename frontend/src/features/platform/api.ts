import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type { WorkspacePlan } from '@/features/administration';

export type PlatformWorkspace = {
  id: string;
  name: string;
  slug: string;
  type: string;
  created_at: string;
  plan: string;
  status: string;
  trial_ends_at: string | null;
  grace_ends_at: string | null;
  status_reason: string | null;
  owners: number;
  seats: { internal: number; client: number };
  clients: number;
  projects: number;
  connections: Record<string, number>;
  overrides: number;
  provisioning: 'complete' | 'incomplete';
  missing: string[];
};

export type PlatformWorkspaceDetail = PlatformWorkspace & {
  plan_detail: Omit<WorkspacePlan, 'plan'> & { name: string };
  history: Array<{ action: string; summary: string | null; at: string }>;
};

export type NewWorkspaceDraft = {
  name: string;
  slug: string;
  type: string;
  locale: string;
  timezone: string;
  currency: string;
  plan: string;
  status: 'trial' | 'active';
  trial_days: number;
  owner_name: string;
  owner_email: string;
};

const KEY = ['platform', 'workspaces'] as const;

export function usePlatformWorkspacesQuery(params: { search: string; status: string }) {
  return useQuery({
    queryKey: [...KEY, params],
    queryFn: async () => {
      const { data } = await api.get<{ data: PlatformWorkspace[]; meta: { total: number } }>(
        '/platform/workspaces',
        { params: { search: params.search || undefined, status: params.status || undefined } },
      );
      return data;
    },
  });
}

export function usePlatformWorkspaceQuery(id: string | null) {
  return useQuery({
    queryKey: [...KEY, 'detail', id],
    enabled: id !== null,
    queryFn: async () => {
      const { data } = await api.get<{ data: PlatformWorkspaceDetail }>(
        `/platform/workspaces/${String(id)}`,
      );
      return data.data;
    },
  });
}

function usePlatformAction<T>(path: (input: T) => string, body: (input: T) => unknown) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: T) => {
      await ensureCsrfCookie();
      const { data } = await api.post<{ data?: unknown } | null>(path(input), body(input));
      return data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useChangePlanMutation(id: string) {
  return usePlatformAction<{ plan: string; reason: string }>(
    () => `/platform/workspaces/${id}/plan`,
    (input) => input,
  );
}

export function useOverrideMutation(id: string) {
  return usePlatformAction<{ kind: string; key: string; value: string; reason: string }>(
    () => `/platform/workspaces/${id}/overrides`,
    (input) => input,
  );
}

export function useChangeStatusMutation(id: string) {
  return usePlatformAction<{ status: string; reason: string }>(
    () => `/platform/workspaces/${id}/status`,
    (input) => input,
  );
}

export function useCreateWorkspaceMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (draft: NewWorkspaceDraft) => {
      await ensureCsrfCookie();
      const { data } = await api.post<{
        data: { workspace_id: string; owner_email: string; one_time_password: string | null };
      }>('/platform/workspaces', { ...draft, slug: draft.slug === '' ? undefined : draft.slug });
      return data.data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}
