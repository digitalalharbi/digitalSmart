import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import type { Paginated } from '@/shared/api/types';
import type { AuditEntry, Member, MembershipStatus, WorkspaceRole } from './types';

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

export function useRemoveMemberMutation() {
  const invalidate = useInvalidateMembers();

  return useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      await ensureCsrfCookie();
      await api.delete(`/members/${userId}`);
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
