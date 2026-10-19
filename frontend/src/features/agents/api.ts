import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/shared/api/client';

export type AgentName = 'analyst' | 'finance' | 'creative' | 'leads' | 'reports' | 'operations';

/** What a specialist agent still sees, with the figures it rests on. Internal only — never on a client page. */
export type AgentFinding = {
  id: string;
  agent: AgentName;
  reason: { code: string; params: Record<string, string | number> };
  message: string;
  severity: 'critical' | 'needs_attention' | 'watch';
  subject: { kind: string; id: string; label: string };
  client: { id: string; name: string | null } | null;
  project: { id: string; name: string | null } | null;
  evidence: Record<string, unknown>;
  task_id: string | null;
  since: string;
};

export type AgentAnswer = { agents: AgentName[]; proposed: number; findings: AgentFinding[] };

export const FINDINGS_KEY = ['agent-findings'] as const;

export function useAgentFindings(enabled = true) {
  return useQuery({
    queryKey: FINDINGS_KEY,
    queryFn: async (): Promise<AgentFinding[]> => {
      const { data } = await api.get<{ data: AgentFinding[] }>('/agents/findings');

      return data.data;
    },
    enabled,
    staleTime: 60_000,
  });
}

export function useAskAgents() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (question: string): Promise<AgentAnswer> => {
      const { data } = await api.post<{ data: AgentAnswer }>('/agents/ask', { question });

      return data.data;
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: FINDINGS_KEY });
      void client.invalidateQueries({ queryKey: ['attention'] });
      void client.invalidateQueries({ queryKey: ['approvals'] });
    },
  });
}
