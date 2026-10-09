import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { Paginated } from '@/shared/api/types';
import type {
  AuditEntry,
  HeldWork,
  InvitedMember,
  Member,
  MemberDraft,
  MembershipStatus,
  WorkspaceRole,
} from './types';

export function useMembersQuery(params: { search?: string; status?: string; page?: number }) {
  return useQuery({
    queryKey: queryKeys.members.list(params),
    queryFn: async (): Promise<Paginated<Member>> => {
      const response = await api.get<Paginated<Member>>('/members', {
        params: {
          search: params.search || undefined,
          status: params.status || undefined,
          page: params.page,
        },
      });
      return response.data;
    },
    placeholderData: (previous) => previous,
  });
}

function useInvalidateMembers() {
  const queryClient = useQueryClient();

  return () => queryClient.invalidateQueries({ queryKey: queryKeys.members.all() });
}

export function useChangeMemberRoleMutation() {
  const invalidate = useInvalidateMembers();

  return useMutation({
    mutationFn: async ({
      userId,
      role,
    }: {
      userId: string;
      role: WorkspaceRole;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.patch(`/members/${userId}/role`, { role });
    },
    onSuccess: () => void invalidate(),
  });
}

export function useChangeMemberStatusMutation() {
  const invalidate = useInvalidateMembers();

  return useMutation({
    mutationFn: async ({
      userId,
      status,
    }: {
      userId: string;
      status: MembershipStatus;
    }): Promise<void> => {
      await ensureCsrfCookie();
      await api.patch(`/members/${userId}/status`, { status });
    },
    onSuccess: () => void invalidate(),
  });
}

/**
 * What this person still holds open, read before deciding to remove them.
 *
 * Only while the question is being asked: `userId` is null unless the confirmation is open, so the member
 * directory does not fetch a figure per row for a decision almost nobody is making.
 */
export function useHeldWorkQuery(userId: string | null) {
  return useQuery({
    queryKey: queryKeys.members.heldWork(userId ?? ''),
    enabled: userId !== null,
    queryFn: async (): Promise<HeldWork> => {
      const response = await api.get<{ data: HeldWork }>(`/members/${userId}/held-work`);
      return response.data.data;
    },
  });
}

/**
 * Remove somebody from the workspace, and report what was released from their name.
 *
 * The response is not empty: removal takes their name off everything still open — tasks, leads, client
 * requests, creative briefs — and says how much of each, because that is the part somebody has to act on.
 * Finished work keeps their name.
 */
export function useRemoveMemberMutation() {
  const invalidate = useInvalidateMembers();

  return useMutation({
    mutationFn: async (userId: string): Promise<HeldWork> => {
      await ensureCsrfCookie();
      const response = await api.delete<{ data: { released: HeldWork } }>(`/members/${userId}`);
      return response.data.data.released;
    },
    onSuccess: () => void invalidate(),
  });
}

export function useAuditLogQuery(params: {
  search?: string;
  securityOnly?: boolean;
  page?: number;
}) {
  return useQuery({
    queryKey: queryKeys.audit.list(params),
    queryFn: async (): Promise<Paginated<AuditEntry>> => {
      const response = await api.get<Paginated<AuditEntry>>('/audit-logs', {
        params: {
          search: params.search || undefined,
          security_only: params.securityOnly ? 1 : undefined,
          page: params.page,
        },
      });
      return response.data;
    },
    placeholderData: (previous) => previous,
  });
}

/**
 * Add a colleague.
 *
 * The response carries a one-time password to read out, and it is the only time it can be read: nothing stores
 * it. The member cannot do anything in the product until they replace it, which is what makes a read-out
 * password tolerable — see `docs/SECURITY.md`.
 */
export function useInviteMemberMutation() {
  const invalidate = useInvalidateMembers();

  return useMutation({
    mutationFn: async (draft: MemberDraft): Promise<InvitedMember> => {
      await ensureCsrfCookie();
      const response = await api.post<{ data: InvitedMember }>('/members', draft);

      return response.data.data;
    },
    onSuccess: () => void invalidate(),
  });
}
