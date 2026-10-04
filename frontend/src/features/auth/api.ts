import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { AuthenticatedUser, Credentials } from './types';

async function fetchSession(): Promise<AuthenticatedUser> {
  const response = await api.get<{ data: AuthenticatedUser }>('/auth/me');
  return response.data.data;
}

/**
 * The current session.
 *
 * A 401 is a definitive answer, not a failure to retry: the shared query client
 * already declines to retry 4xx, so an unauthenticated visitor settles into the
 * error state immediately and the router sends them to sign in.
 */
export function useSessionQuery() {
  return useQuery({
    queryKey: queryKeys.auth.session(),
    queryFn: fetchSession,
    staleTime: 5 * 60_000,
  });
}

export function useLoginMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (credentials: Credentials): Promise<AuthenticatedUser> => {
      // Sanctum's stateful guard needs the CSRF cookie before the POST.
      await ensureCsrfCookie();
      const response = await api.post<{ data: AuthenticatedUser }>('/auth/login', credentials);
      return response.data.data;
    },
    onSuccess: (user) => {
      queryClient.setQueryData(queryKeys.auth.session(), user);
    },
  });
}

export function useLogoutMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<void> => {
      await api.post('/auth/logout');
    },
    onSettled: () => {
      // Clear every cached query: the next session may be a different person
      // in a different workspace, and stale client data must not leak across.
      queryClient.clear();
    },
  });
}

export function useSwitchWorkspaceMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (workspaceId: string): Promise<AuthenticatedUser> => {
      await ensureCsrfCookie();
      const response = await api.post<{ data: AuthenticatedUser }>(
        `/workspaces/${workspaceId}/activate`,
      );
      return response.data.data;
    },
    onSuccess: (user) => {
      queryClient.setQueryData(queryKeys.auth.session(), user);
      // Everything else was scoped to the previous workspace.
      void queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'auth' });
    },
  });
}
