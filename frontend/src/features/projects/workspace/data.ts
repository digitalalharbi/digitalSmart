import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import type { Project, ProjectMember } from '../types';

/*
 * What one project is made of, each read narrowed to that project on the server where the endpoint
 * accepts it and narrowed here (by the project id every row already carries) where it does not.
 *
 * Every query is independent and none retries: a reader who may not see leads still sees the budget, and a
 * tab whose read failed says so instead of holding the others hostage.
 */

export type Readiness = {
  project: { id: string; name: string; status: string };
  overall: { state: string; label: string };
  ready_count: number;
  total: number;
};

export type CampaignRow = {
  id: string;
  name: string;
  client_name?: string | null;
  provider?: string | null;
  health: 'healthy' | 'watch' | 'needs_attention' | 'critical' | 'inactive' | 'insufficient_data';
  health_reason: string;
  spend_minor: number | null;
  currency: string | null;
};

export type CampaignList = {
  rows: CampaignRow[];
  counts: Record<string, number>;
  total: number;
};

export type BudgetRow = {
  id: string;
  name: string;
  currency: string;
  approved_minor: number;
  allocated_minor: number;
  spent_minor: number | null;
  pacing: { status: string; status_label: string; utilisation: number | null } | null;
  risks: Array<{ risk: string; label: string }>;
};

export type LeadFunnel = { total: number; open: number; won: number };

export type TaskLite = {
  id: string;
  title: string;
  state_label: string;
  priority_label: string;
  assignee_name: string | null;
  due_on: string | null;
  is_overdue: boolean;
  project_id: string | null;
};

export type CreativeLite = {
  id: string;
  title: string;
  state: string;
  state_label: string;
  kind_label: string;
  provider: string | null;
  owner_name: string | null;
  due_at: string | null;
  is_overdue: boolean;
  project_id: string | null;
};

export type ApprovalLite = {
  id: string;
  kind_label: string;
  title: string;
  requested_by_name: string | null;
  waiting_days: number | null;
  project_id: string | null;
};

export type ReportLite = {
  id: string;
  name: string;
  window_label: string;
  audience: string;
  active_share_count: number;
  updated_at: string;
};

export type ActivityRow = {
  id: string;
  sentence: string;
  actor: string | null;
  project_id: string | null;
  at: string;
};

export type AttentionLite = {
  id: string;
  message: string;
  severity: 'critical' | 'needs_attention' | 'watch';
  project_id: string | null;
  action: { path: string };
};

const STALE = 30_000;

export const useProjectDetail = (projectId: string) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'detail', projectId] as const,
    queryFn: async (): Promise<Project> => {
      const { data } = await api.get<{ data: Project }>(`/projects/${projectId}`);

      return data.data;
    },
    staleTime: STALE,
    retry: false,
  });

export const useProjectTeam = (projectId: string) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'team', projectId] as const,
    queryFn: async (): Promise<ProjectMember[]> => {
      const { data } = await api.get<{ data: ProjectMember[] }>(`/projects/${projectId}/members`);

      return data.data;
    },
    staleTime: STALE,
    retry: false,
  });

export const useReadiness = (projectId: string) =>
  useQuery({
    queryKey: ['projects', 'readiness', projectId] as const,
    queryFn: async (): Promise<Readiness> => {
      const { data } = await api.get<{ data: Readiness }>(`/projects/${projectId}/readiness`);

      return data.data;
    },
  });

export const useProjectCampaigns = (projectId: string, limit = 8, enabled = true) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'campaigns', projectId, limit] as const,
    queryFn: async (): Promise<CampaignList> => {
      const { data } = await api.get<{
        data: CampaignRow[];
        meta: { health_counts: Record<string, number>; total: number };
      }>('/campaigns/control', { params: { project_id: projectId, limit, sort: 'health' } });

      return { rows: data.data, counts: data.meta.health_counts, total: data.meta.total };
    },
    enabled,
    staleTime: STALE,
    retry: false,
  });

/**
 * The project's own budgets, or — when it has none of its own — the client's, said so by `scope`.
 *
 * Most agencies approve a budget per client per month rather than per project, and a project page that
 * answered "no budget" there would be wrong about the thing the reader came to see. The scope travels with
 * the rows so the page never presents a client's money as a project's.
 */
export const useProjectBudgets = (
  projectId: string,
  clientId: string | undefined,
  enabled = true,
) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'budgets', projectId, clientId ?? ''] as const,
    queryFn: async (): Promise<{ rows: BudgetRow[]; scope: 'project' | 'client' }> => {
      const own = await api.get<{ data: BudgetRow[] }>('/budgets/control', {
        params: { project_id: projectId },
      });

      if (own.data.data.length > 0 || clientId === undefined) {
        return { rows: own.data.data, scope: 'project' };
      }

      const shared = await api.get<{ data: Array<BudgetRow & { project: unknown }> }>(
        '/budgets/control',
        { params: { client_id: clientId } },
      );

      return { rows: shared.data.data.filter((row) => row.project === null), scope: 'client' };
    },
    enabled: enabled && clientId !== undefined,
    staleTime: STALE,
    retry: false,
  });

export const useProjectLeads = (projectId: string, enabled = true) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'leads', projectId] as const,
    queryFn: async (): Promise<LeadFunnel> => {
      const { data } = await api.get<{ data: { funnel: LeadFunnel } }>('/leads/centre', {
        params: { project_id: projectId },
      });

      return data.data.funnel;
    },
    enabled,
    staleTime: STALE,
    retry: false,
  });

export const useProjectTasks = (projectId: string, enabled = true) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'tasks', projectId] as const,
    queryFn: async (): Promise<{ open: TaskLite[]; overdue: number; total: number }> => {
      const { data } = await api.get<{
        data: {
          columns: Array<{ state: string; tasks: TaskLite[] }>;
          workload: { open: number; overdue: number };
        };
      }>('/tasks/board', { params: { project_id: projectId } });
      const open = data.data.columns
        .filter((column) => column.state !== 'done' && column.state !== 'cancelled')
        .flatMap((column) => column.tasks);

      return { open, overdue: data.data.workload.overdue, total: data.data.workload.open };
    },
    enabled,
    staleTime: STALE,
    retry: false,
  });

export const useProjectCreative = (projectId: string, enabled: boolean) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'creative', projectId] as const,
    queryFn: async (): Promise<CreativeLite[]> => {
      const { data } = await api.get<{
        data: { columns: Array<{ requests: CreativeLite[] }> };
      }>('/creative/board', { params: { project_id: projectId } });

      return data.data.columns.flatMap((column) => column.requests);
    },
    enabled,
    staleTime: STALE,
    retry: false,
  });

export const useProjectApprovals = (projectId: string, enabled: boolean) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'approvals', projectId] as const,
    queryFn: async (): Promise<ApprovalLite[]> => {
      const { data } = await api.get<{ data: { pending: ApprovalLite[] } }>('/approvals/queue', {
        params: { project_id: projectId },
      });

      return data.data.pending;
    },
    enabled,
    staleTime: STALE,
    retry: false,
  });

export const useClientReports = (clientId: string | undefined, enabled = true) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'reports', clientId ?? ''] as const,
    queryFn: async (): Promise<ReportLite[]> => {
      const { data } = await api.get<{ data: ReportLite[] }>('/reports', {
        params: { client_id: clientId },
      });

      return data.data;
    },
    enabled: enabled && clientId !== undefined,
    staleTime: STALE,
    retry: false,
  });

export const useProjectActivity = (projectId: string, enabled = true) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'activity', projectId] as const,
    queryFn: async (): Promise<ActivityRow[]> => {
      /* Asked of the server for this project, so a quiet one is not lost behind the agency's busy ones. */
      const { data } = await api.get<{ data: ActivityRow[] }>('/activity', {
        params: { limit: 20, project_id: projectId },
      });

      return data.data;
    },
    enabled,
    staleTime: STALE,
    retry: false,
  });

export const useProjectAttention = (projectId: string) =>
  useQuery({
    queryKey: ['projects', 'workspace', 'attention', projectId] as const,
    queryFn: async (): Promise<AttentionLite[]> => {
      const { data } = await api.get<{ data: AttentionLite[] }>('/attention', {
        params: { limit: 20, project_id: projectId },
      });

      return data.data;
    },
    staleTime: STALE,
    retry: false,
  });
