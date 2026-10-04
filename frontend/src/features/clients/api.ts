import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { PageMeta } from '@/shared/api/types';
import type { Client, ClientListParams, ClientPayload, ClientStatus } from './types';

type ClientPage = { data: Client[]; meta: PageMeta };

export function useClientsQuery(params: ClientListParams) {
  return useQuery({
    queryKey: queryKeys.clients.list(params),
    queryFn: async (): Promise<ClientPage> => {
      const response = await api.get<ClientPage>('/clients', {
        params: {
          search: params.search || undefined,
          status: params.status?.length ? params.status : undefined,
          page: params.page,
          per_page: params.per_page,
          sort: params.sort,
          direction: params.direction,
        },
      });
      return response.data;
    },
    // Keeps the table on screen while a new page or filter loads.
    placeholderData: (previous) => previous,
  });
}

function useInvalidateClients() {
  const queryClient = useQueryClient();

  return () => queryClient.invalidateQueries({ queryKey: queryKeys.clients.all() });
}

export function useCreateClientMutation() {
  const invalidate = useInvalidateClients();

  return useMutation({
    mutationFn: async (payload: ClientPayload): Promise<Client> => {
      await ensureCsrfCookie();
      const response = await api.post<{ data: Client }>('/clients', payload);
      return response.data.data;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useUpdateClientMutation() {
  const invalidate = useInvalidateClients();

  return useMutation({
    mutationFn: async ({
      id,
      payload,
    }: {
      id: string;
      payload: ClientPayload;
    }): Promise<Client> => {
      await ensureCsrfCookie();
      const response = await api.put<{ data: Client }>(`/clients/${id}`, payload);
      return response.data.data;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useChangeClientStatusMutation() {
  const invalidate = useInvalidateClients();

  return useMutation({
    mutationFn: async ({ id, status }: { id: string; status: ClientStatus }): Promise<Client> => {
      await ensureCsrfCookie();
      const response = await api.patch<{ data: Client }>(`/clients/${id}/status`, { status });
      return response.data.data;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useDeleteClientMutation() {
  const invalidate = useInvalidateClients();

  return useMutation({
    mutationFn: async (id: string): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/clients/${id}`);
    },
    onSuccess: () => void invalidate(),
  });
}
