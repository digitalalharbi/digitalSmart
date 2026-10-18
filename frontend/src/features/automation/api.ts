import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import type { Rule, RuleDraft } from './types';

const KEY = ['automation', 'rules'] as const;

export function useRulesQuery() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<Rule[]> => {
      const { data } = await api.get<{ data: Rule[] }>('/automation/rules');

      return data.data;
    },
    staleTime: 30_000,
  });
}

function useRuleMutation<T>(fn: (input: T) => Promise<unknown>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      /* A rule changes what the attention queue says, so that is re-read too. */
      void client.invalidateQueries({ queryKey: KEY });
      void client.invalidateQueries({ queryKey: ['attention'] });
    },
  });
}

export const useCreateRule = () =>
  useRuleMutation((draft: RuleDraft) => api.post('/automation/rules', draft));

export const useUpdateRule = () =>
  useRuleMutation(({ id, draft }: { id: string; draft: RuleDraft }) =>
    api.put(`/automation/rules/${id}`, draft),
  );

export const useDeleteRule = () =>
  useRuleMutation((id: string) => api.delete(`/automation/rules/${id}`));
