import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/shared/api/client';

export type AgentRun = {
  agent: string;
  status: 'analysing' | 'preparing' | 'waiting_approval' | 'completed' | 'failed';
  looked_at: number;
  proposed: number;
  summary: Record<string, unknown>;
  started_at: string;
  finished_at: string | null;
};

export type ToolRow = {
  name: string;
  description: string;
  risk: 'read' | 'internal' | 'proposal';
  requires_approval: boolean;
  calls_24h: number;
  refused_24h: number;
};

export type ProviderRow = {
  provider: string;
  label: string;
  status_write: 'documented' | 'unverified' | 'not_supported';
  budget_write: 'documented' | 'unverified' | 'not_supported';
  connected: boolean;
  /** The platform's own MCP server, as its official documentation describes it. */
  mcp: {
    trust: 'official' | 'none';
    endpoint: string | null;
    reads: boolean;
    writes: 'documented' | 'planned' | 'none';
    source: string | null;
  };
  read_route: 'mcp' | 'direct_api' | 'unavailable';
  write_route: 'mcp' | 'direct_api' | 'unavailable';
};

export type AgentOverview = {
  runs: AgentRun[];
  tools: ToolRow[];
  providers: ProviderRow[];
  routines: Array<{ key: string; schedule: string; last_run: string | null }>;
  mcp_endpoint: string;
};

const KEY = ['ai-center'] as const;

export function useAgentOverview() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<AgentOverview> => {
      const { data } = await api.get<{ data: AgentOverview }>('/agents/overview');

      return data.data;
    },
    staleTime: 15_000,
  });
}

export function useRunAgents() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: () => api.post('/agents/run'),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: KEY });
      void client.invalidateQueries({ queryKey: ['approvals'] });
      void client.invalidateQueries({ queryKey: ['attention'] });
    },
  });
}

export type McpToken = {
  id: string;
  name: string;
  last_used_at: string | null;
  created_at: string | null;
};

export function useMcpTokens() {
  return useQuery({
    queryKey: ['ai-center', 'tokens'] as const,
    queryFn: async (): Promise<McpToken[]> => {
      const { data } = await api.get<{ data: McpToken[] }>('/mcp/tokens');

      return data.data;
    },
  });
}

export function useCreateMcpToken() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (name: string): Promise<string> => {
      const { data } = await api.post<{ data: { token: string } }>('/mcp/tokens', { name });

      return data.data.token;
    },
    onSuccess: () => void client.invalidateQueries({ queryKey: ['ai-center', 'tokens'] }),
  });
}

export function useRevokeMcpToken() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => api.delete(`/mcp/tokens/${id}`),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['ai-center', 'tokens'] }),
  });
}
