import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';

export type BusinessState = 'healthy' | 'needs_reconnect' | 'sync_issue' | 'awaiting_access';

export type Connection = {
  id: string;
  provider: string;
  provider_label: string;
  /** A human label — "Meta Ads — Alhambra" — never only a provider id. */
  label: string;
  owner: 'agency' | 'client';
  owner_client: { id: string; name: string } | null;
  state: BusinessState;
  state_label: string;
  needs_action: boolean;
  accounts: { discovered: number; bound: number; unassigned: number };
  last_checked_at: string | null;
  expires_at: string | null;
  /** Present only for somebody who may act on it. */
  advanced: { message: string } | null;
};

export type AccountState = 'unassigned' | 'bound' | 'shared' | 'ignored';

export type QueueAccount = {
  id: string;
  name: string;
  provider: string;
  external_id: string;
  connection: { id: string; label: string };
  owner_client: { id: string; name: string } | null;
  currency: string | null;
  status: string;
  state: AccountState;
  projects: Array<{ id: string; name: string; client_name: string }>;
  campaigns: number;
};

export function useConnectionsQuery() {
  return useQuery({
    queryKey: queryKeys.integrations.connections(),
    queryFn: async (): Promise<Connection[]> => {
      const { data } = await api.get<{ data: Connection[] }>('/integrations/connections');

      return data.data;
    },
  });
}

export function useAccountQueueQuery(states: AccountState[]) {
  return useQuery({
    queryKey: queryKeys.integrations.queue(states),
    queryFn: async (): Promise<{ data: QueueAccount[]; counts: Record<AccountState, number> }> => {
      const { data } = await api.get<{
        data: QueueAccount[];
        meta: { counts: Record<AccountState, number> };
      }>('/integrations/account-queue', { params: { state: states, limit: 100 } });

      return { data: data.data, counts: data.meta.counts };
    },
  });
}

function useInvalidate() {
  const client = useQueryClient();

  return () => client.invalidateQueries({ queryKey: queryKeys.integrations.all() });
}

export function useConnectClientMutation() {
  const invalidate = useInvalidate();

  return useMutation({
    mutationFn: async (input: {
      provider: string;
      client_id: string;
      credentials: Record<string, string>;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.post('/integrations/connections', input);
    },
    onSuccess: () => void invalidate(),
  });
}

export function useConnectionAction() {
  const invalidate = useInvalidate();

  return useMutation({
    mutationFn: async ({
      id,
      action,
    }: {
      id: string;
      action: 'discover' | 'disconnect';
    }): Promise<void> => {
      await ensureCsrfCookie();
      await (action === 'discover'
        ? api.post(`/integrations/connections/${id}/discover`)
        : api.delete(`/integrations/connections/${id}`));
    },
    onSuccess: () => void invalidate(),
  });
}

export function useTriageAccount() {
  const invalidate = useInvalidate();

  return useMutation({
    mutationFn: async ({ id, ignore }: { id: string; ignore: boolean }): Promise<void> => {
      await ensureCsrfCookie();
      await (ignore
        ? api.post(`/integrations/accounts/${id}/ignore`)
        : api.delete(`/integrations/accounts/${id}/ignore`));
    },
    onSuccess: () => void invalidate(),
  });
}

export function useAssignAccount() {
  const invalidate = useInvalidate();

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
