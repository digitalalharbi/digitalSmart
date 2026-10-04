import { useCallback } from 'react';
import { useSessionQuery } from './api';
import type { PermissionSlug } from './permissions';
import type { AuthenticatedUser } from './types';

type SessionState = {
  user: AuthenticatedUser | undefined;
  isLoading: boolean;
  isAuthenticated: boolean;
  /** Whether the UI should offer an action. The server still decides. */
  can: (permission: PermissionSlug) => boolean;
};

export function useSession(): SessionState {
  const { data, isPending, isSuccess } = useSessionQuery();

  const can = useCallback(
    (permission: PermissionSlug): boolean => data?.permissions.includes(permission) ?? false,
    [data],
  );

  return {
    user: data,
    isLoading: isPending,
    isAuthenticated: isSuccess && data !== undefined,
    can,
  };
}
