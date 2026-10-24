import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type {
  AccessDetail,
  AccessFigures,
  AccessInbox,
  AccessRequestRow,
  AccessSettings,
  ContactAction,
  InvitationPreview,
} from './types';

const keys = {
  all: ['access-requests'] as const,
  inbox: () => [...keys.all, 'inbox'] as const,
  one: (id: string) => [...keys.all, 'one', id] as const,
  figures: (days: number) => [...keys.all, 'figures', days] as const,
};

export function useAccessInboxQuery() {
  return useQuery({
    queryKey: keys.inbox(),
    queryFn: async (): Promise<AccessInbox> => {
      const { data } = await api.get<{ data: AccessRequestRow[]; meta: Omit<AccessInbox, 'rows'> }>(
        '/access-requests',
      );

      return { rows: data.data, ...data.meta };
    },
    staleTime: 15_000,
  });
}

export function useAccessRequestQuery(id: string) {
  return useQuery({
    queryKey: keys.one(id),
    queryFn: async (): Promise<{ request: AccessDetail; delivery: AccessInbox['delivery'] }> => {
      const { data } = await api.get<{
        data: AccessDetail;
        meta: { delivery: AccessInbox['delivery'] };
      }>(`/access-requests/${id}`);

      return { request: data.data, delivery: data.meta.delivery };
    },
    staleTime: 10_000,
  });
}

export function useAccessFiguresQuery(days = 30) {
  return useQuery({
    queryKey: keys.figures(days),
    queryFn: async (): Promise<AccessFigures> => {
      const { data } = await api.get<{ data: AccessFigures }>('/access-requests/figures', {
        params: { days },
      });

      return data.data;
    },
    staleTime: 60_000,
  });
}

/** One write on a request, then everything that shows it is read again. */
function useRequestAction<TInput>(send: (id: string, input: TInput) => Promise<unknown>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: TInput }) => send(id, input),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: keys.all });
    },
  });
}

export const useAssignMutation = () =>
  useRequestAction((id, ownerId: string | null) =>
    api.post(`/access-requests/${id}/assign`, { owner_id: ownerId }),
  );

export const useContactMutation = () =>
  useRequestAction((id, input: { action: ContactAction; note: string }) =>
    api.post(`/access-requests/${id}/contact`, {
      action: input.action,
      note: input.note.trim() === '' ? null : input.note.trim(),
    }),
  );

export const useNoteMutation = () =>
  useRequestAction((id, body: string) => api.post(`/access-requests/${id}/notes`, { body }));

export const useFollowUpMutation = () =>
  useRequestAction((id, at: string | null) => api.post(`/access-requests/${id}/follow-up`, { at }));

export const useMoveMutation = () =>
  useRequestAction((id, status: string) => api.post(`/access-requests/${id}/status`, { status }));

export const useApproveMutation = () =>
  useRequestAction((id, note: string) =>
    api.post(`/access-requests/${id}/approve`, { note: note.trim() === '' ? null : note.trim() }),
  );

export const useRejectMutation = () =>
  useRequestAction((id, input: { reason: string; message: string }) =>
    api.post(`/access-requests/${id}/reject`, {
      reason: input.reason,
      message: input.message.trim() === '' ? null : input.message.trim(),
    }),
  );

export const useSharedMutation = () =>
  useRequestAction((id, _input: null) => api.post(`/access-requests/${id}/invitation/shared`));

/** The link comes back once and is never stored: the caller must show it now. */
export function useInviteMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async ({
      id,
      role,
      projectIds,
    }: {
      id: string;
      role: string;
      projectIds: string[];
    }): Promise<{ path: string; hint: string; expires_at: string }> => {
      const { data } = await api.post<{
        data: { path: string; hint: string; expires_at: string };
      }>(`/access-requests/${id}/invitation`, { role, project_ids: projectIds });

      return data.data;
    },
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: keys.all });
    },
  });
}

export function useSaveSettingsMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (settings: Pick<AccessSettings, 'response_minutes' | 'default_owner_id'>) => {
      await api.put('/access-requests/settings', settings);
    },
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: keys.all });
    },
  });
}

/* ------------------------------------------------------------------ public ------------------------------------------------------------------ */

export function useInvitationQuery(token: string) {
  return useQuery({
    queryKey: ['public', 'invitation', token] as const,
    queryFn: async (): Promise<InvitationPreview> => {
      const { data } = await api.get<{ data: InvitationPreview }>(`/public/invitations/${token}`);

      return data.data;
    },
    retry: false,
    staleTime: Infinity,
  });
}

export function useAcceptInvitationMutation(token: string) {
  return useMutation({
    mutationFn: async (password: string | null): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(
        `/public/invitations/${token}/accept`,
        password === null ? {} : { password, password_confirmation: password },
      );
    },
  });
}
