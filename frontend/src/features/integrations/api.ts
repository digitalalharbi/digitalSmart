import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { ExternalAccount, ProjectBinding, ProviderRow, ProviderSlug } from './types';

export function useProvidersQuery() {
  return useQuery({
    queryKey: queryKeys.integrations.providers(),
    queryFn: async (): Promise<ProviderRow[]> => {
      const response = await api.get<{ data: ProviderRow[] }>('/integrations/providers');
      return response.data.data;
    },
  });
}

export function useExternalAccountsQuery(provider?: ProviderSlug) {
  return useQuery({
    queryKey: queryKeys.integrations.accounts(provider ?? 'all'),
    queryFn: async (): Promise<ExternalAccount[]> => {
      const response = await api.get<{ data: ExternalAccount[] }>('/integrations/accounts', {
        params: { provider },
      });
      return response.data.data;
    },
  });
}

function useInvalidateIntegrations() {
  const queryClient = useQueryClient();

  return () => queryClient.invalidateQueries({ queryKey: queryKeys.integrations.all() });
}

export function useConfigureConnectionMutation() {
  const invalidate = useInvalidateIntegrations();

  return useMutation({
    mutationFn: async ({
      provider,
      credentials,
    }: {
      provider: ProviderSlug;
      credentials: Record<string, string>;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.put(`/integrations/providers/${provider}`, { credentials });
    },
    onSuccess: () => void invalidate(),
  });
}

export function useDiscoverAccountsMutation() {
  const invalidate = useInvalidateIntegrations();

  return useMutation({
    mutationFn: async (provider: ProviderSlug): Promise<number> => {
      await ensureCsrfCookie();
      const response = await api.post<{ meta: { count: number } }>(
        `/integrations/providers/${provider}/discover`,
      );
      return response.data.meta.count;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useDisconnectProviderMutation() {
  const invalidate = useInvalidateIntegrations();

  return useMutation({
    mutationFn: async (provider: ProviderSlug): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/integrations/providers/${provider}`);
    },
    onSuccess: () => void invalidate(),
  });
}

export function useProjectBindingsQuery(projectId: string | undefined) {
  return useQuery({
    queryKey: queryKeys.integrations.bindings(projectId ?? ''),
    queryFn: async (): Promise<ProjectBinding[]> => {
      const response = await api.get<{ data: ProjectBinding[] }>(
        `/projects/${projectId ?? ''}/integrations`,
      );
      return response.data.data;
    },
    enabled: Boolean(projectId),
  });
}

export function useBindAccountMutation() {
  const invalidate = useInvalidateIntegrations();

  return useMutation({
    mutationFn: async ({
      projectId,
      accountId,
    }: {
      projectId: string;
      accountId: string;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(`/projects/${projectId}/integrations`, { external_account_id: accountId });
    },
    onSuccess: () => void invalidate(),
  });
}

export function useUnbindAccountMutation() {
  const invalidate = useInvalidateIntegrations();

  return useMutation({
    mutationFn: async ({
      projectId,
      accountId,
    }: {
      projectId: string;
      accountId: string;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/projects/${projectId}/integrations/${accountId}`);
    },
    onSuccess: () => void invalidate(),
  });
}

export function useSetBindingSyncMutation() {
  const invalidate = useInvalidateIntegrations();

  return useMutation({
    mutationFn: async ({
      projectId,
      accountId,
      enabled,
    }: {
      projectId: string;
      accountId: string;
      enabled: boolean;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.patch(`/projects/${projectId}/integrations/${accountId}`, {
        sync_enabled: enabled,
      });
    },
    onSuccess: () => void invalidate(),
  });
}

/** The windows the server offers for reading history. A short list, not a free field. */
export const BACKFILL_WINDOWS = [30, 90, 180, 365] as const;

export type BackfillResult = {
  account_id: string;
  days: number;
  from: string;
  until: string;
  queue: string;
};

/** Read an account's history at the lowest priority. Refused for six hours after a request. */
export function useBackfillMutation() {
  return useMutation({
    mutationFn: async ({
      accountId,
      days,
    }: {
      accountId: string;
      days: (typeof BACKFILL_WINDOWS)[number];
    }): Promise<BackfillResult> => {
      await ensureCsrfCookie();
      const { data } = await api.post<{ data: BackfillResult }>(
        `/integrations/accounts/${accountId}/backfill`,
        { days },
      );

      return data.data;
    },
  });
}
