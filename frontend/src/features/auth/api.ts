import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie, abortInFlightRequests } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { AuthenticatedUser, Credentials, PasswordChange } from './types';

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
      /*
       * Safe to send again if the connection drops with no response, which is the one write in
       * this product that is: signing in twice signs you in once. Without this, a single dropped
       * connection tells somebody the server is unreachable while they are looking at a page it
       * served a moment earlier.
       */
      const response = await api.post<{ data: AuthenticatedUser }>('/auth/login', credentials, {
        retryOnNetworkError: true,
      });
      return response.data.data;
    },
    onSuccess: (user) => {
      queryClient.setQueryData(queryKeys.auth.session(), user);
    },
  });
}

/**
 * Sign out.
 *
 * **Retried on a dropped connection, and the cache is cleared only once the server has answered.** Both halves
 * were wrong, and an end-to-end run under load showed what that costs: the sign-out POST failed with no
 * response, the interface navigated to the sign-in screen regardless, and the back route to a client list
 * rendered three clients — because the session was never ended. A sign-out that did not happen must not look
 * like one that did.
 *
 * Safe to send again, which is why the retry belongs here and not on a password change: signing out twice signs
 * you out once.
 */
export function useLogoutMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<void> => {
      /* First, so no answer to something asked while signed in can arrive after, and put the session back. */
      abortInFlightRequests();
      await api.post('/auth/logout', undefined, { retryOnNetworkError: true });
    },
    onSuccess: () => {
      /*
       * Cleared on success rather than on settle. The next session may be a different person in a different
       * workspace, so nothing cached may survive — but clearing it after a *failed* sign-out empties the screen
       * of somebody who is still signed in, which reads as "signed out" and is not.
       */
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

/**
 * Change your own password.
 *
 * Deliberately **not** retried on a dropped connection, unlike signing in. Signing in twice signs you in once; a
 * password change retried against a server that already applied it would send the old password a second time and
 * be refused, and the reader would be told their current password was wrong when in fact it had just worked.
 *
 * Every other session using the old password ends server-side. This one survives, so nothing here signs the
 * reader out of the screen they are looking at.
 */
export function useChangePasswordMutation() {
  return useMutation({
    mutationFn: async (change: PasswordChange): Promise<void> => {
      await api.post('/auth/password', change);
    },
  });
}
