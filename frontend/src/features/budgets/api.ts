import { api } from '@/shared/api/client';
import type {
  BudgetDraft,
  BudgetPayload,
  AllocationDraft,
  ForecastInput,
  ForecastResponse,
  PacingPayload,
} from './types';

/** A draft on the wire. The amount stays a decimal string, as the API takes it. */
function toRequest(draft: BudgetDraft): Record<string, unknown> {
  return {
    client_id: draft.client_id,
    project_id: draft.project_id,
    name: draft.name,
    period_start: draft.period_start,
    period_end: draft.period_end,
    amount: draft.amount,
    currency: draft.currency,
    notes: draft.notes,
  };
}

/** The allocation tree on the wire: editing-session keys are dropped. */
function toAllocations(drafts: AllocationDraft[]): Array<Record<string, unknown>> {
  return drafts.map((draft) => ({
    level: draft.level,
    provider: draft.provider,
    label: draft.label,
    amount: draft.amount,
    campaign_id: draft.campaign_id,
    ad_group_id: draft.ad_group_id,
    children: toAllocations(draft.children),
  }));
}

export async function fetchBudgets(params: {
  status?: string[];
  client_id?: string | null;
}): Promise<BudgetPayload[]> {
  const { data } = await api.get<{ data: BudgetPayload[] }>('/budgets', {
    params: {
      ...(params.status === undefined ? {} : { status: params.status }),
      ...(params.client_id == null ? {} : { client_id: params.client_id }),
    },
  });

  return data.data;
}

export async function fetchBudget(id: string): Promise<BudgetPayload> {
  const { data } = await api.get<{ data: BudgetPayload }>(`/budgets/${id}`);

  return data.data;
}

export async function createBudget(draft: BudgetDraft): Promise<BudgetPayload> {
  const { data } = await api.post<{ data: BudgetPayload }>('/budgets', toRequest(draft));

  return data.data;
}

export async function updateBudget(id: string, draft: BudgetDraft): Promise<BudgetPayload> {
  const { data } = await api.put<{ data: BudgetPayload }>(`/budgets/${id}`, toRequest(draft));

  return data.data;
}

export async function deleteBudget(id: string): Promise<void> {
  await api.delete(`/budgets/${id}`);
}

export async function approveBudget(id: string): Promise<BudgetPayload> {
  const { data } = await api.post<{ data: BudgetPayload }>(`/budgets/${id}/approve`);

  return data.data;
}

export async function archiveBudget(id: string): Promise<BudgetPayload> {
  const { data } = await api.post<{ data: BudgetPayload }>(`/budgets/${id}/archive`);

  return data.data;
}

export async function saveAllocations(
  id: string,
  allocations: AllocationDraft[],
): Promise<BudgetPayload> {
  const { data } = await api.put<{ data: BudgetPayload }>(`/budgets/${id}/allocations`, {
    allocations: toAllocations(allocations),
  });

  return data.data;
}

export async function fetchPacing(id: string): Promise<PacingPayload> {
  const { data } = await api.get<{ data: PacingPayload }>(`/budgets/${id}/pacing`);

  return data.data;
}

export async function fetchAlerts(): Promise<PacingPayload[]> {
  const { data } = await api.get<{ data: PacingPayload[] }>('/budgets/alerts');

  return data.data;
}

export async function runForecast(input: ForecastInput): Promise<ForecastResponse> {
  const { data } = await api.post<{ data: ForecastResponse }>('/budgets/forecast', input);

  return data.data;
}
