import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';
import type { FormDraft, FormStatus, FormSummary } from './types';

const KEY = ['tracking', 'forms'] as const;

export function useFormsQuery() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<FormSummary[]> =>
      (await api.get<{ data: FormSummary[] }>('/tracking/forms')).data.data,
  });
}

export function useSaveFormMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { id?: string; draft: FormDraft }): Promise<FormSummary> => {
      await ensureCsrfCookie();
      const { data } =
        input.id === undefined
          ? await api.post<{ data: FormSummary }>('/tracking/forms', input.draft)
          : await api.put<{ data: FormSummary }>(`/tracking/forms/${input.id}`, input.draft);

      return data.data;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useSetFormStatusMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { id: string; status: FormStatus }): Promise<void> => {
      await ensureCsrfCookie();
      await api.post(`/tracking/forms/${input.id}/status`, { status: input.status });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: KEY }),
  });
}
