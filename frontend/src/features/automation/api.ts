import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import type {
  Capability,
  ChangeEntry,
  Execution,
  Rule,
  RuleDraft,
  Simulation,
  Summary,
} from './types';

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

export function useAutomationSummary(days: number) {
  return useQuery({
    queryKey: ['automation', 'summary', days] as const,
    queryFn: async (): Promise<Summary> =>
      (await api.get<{ data: Summary }>('/automation/summary', { params: { days } })).data.data,
    staleTime: 30_000,
  });
}

export type HistoryFilters = {
  days: number;
  result?: string | null;
  provider?: string | null;
  rule_id?: string | null;
};

export function useActionHistory(filters: HistoryFilters) {
  return useQuery({
    queryKey: ['automation', 'history', filters] as const,
    queryFn: async (): Promise<Execution[]> =>
      (await api.get<{ data: Execution[] }>('/automation/history', { params: filters })).data.data,
    staleTime: 30_000,
  });
}

export function useChangeLog(days: number) {
  return useQuery({
    queryKey: ['automation', 'changes', days] as const,
    queryFn: async (): Promise<ChangeEntry[]> =>
      (await api.get<{ data: ChangeEntry[] }>('/automation/changes', { params: { days } })).data
        .data,
    staleTime: 30_000,
  });
}

export function useCapabilities() {
  return useQuery({
    queryKey: ['automation', 'capabilities'] as const,
    queryFn: async (): Promise<Capability[]> =>
      (await api.get<{ data: Capability[] }>('/automation/capabilities')).data.data,
    staleTime: 300_000,
  });
}

export function useSimulate() {
  return useMutation({
    mutationFn: async (draft: RuleDraft): Promise<Simulation> =>
      (await api.post<{ data: Simulation }>('/automation/rules/simulate', draft)).data.data,
  });
}

export type AgentStatus = {
  agent: string;
  status: 'active' | 'failing' | 'never';
  last_run: string | null;
  last_status: string | null;
  looked_at: number | null;
  success_rate: number | null;
  runs: number;
  open_recommendations: number;
  routines: string[];
};

export type RoutineStatus = {
  routine: string;
  agent: string;
  schedule: { every_minutes?: number; at?: string; days?: number[] | null; timezone?: string };
  last_run: string | null;
  last_status: string | null;
  next_run: string;
};

export function useAgentsStatus() {
  return useQuery({
    queryKey: ['agents', 'status'] as const,
    queryFn: async (): Promise<{ agents: AgentStatus[]; routines: RoutineStatus[] }> =>
      (
        await api.get<{ data: { agents: AgentStatus[]; routines: RoutineStatus[] } }>(
          '/agents/status',
        )
      ).data.data,
    staleTime: 30_000,
  });
}
