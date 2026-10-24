import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie } from '@/shared/api/client';

export const OBJECTIVES = [
  'awareness',
  'reach',
  'traffic',
  'engagement',
  'video',
  'leads',
  'sales',
  'app_install',
] as const;
export type Objective = (typeof OBJECTIVES)[number];

export type PlanStatus =
  | 'draft'
  | 'ready_for_review'
  | 'approved'
  | 'queued'
  | 'creating'
  | 'partial'
  | 'created_paused'
  | 'verified'
  | 'activation_pending'
  | 'active'
  | 'failed'
  | 'cancelled';

export type PlanInput = {
  project_id: string;
  name: string;
  objective: Objective;
  budget_type: 'daily' | 'lifetime';
  budget_minor: number;
  currency: string;
  starts_at: string;
  ends_at: string | null;
  external_account_ids: string[];
  tracking_link_id: string | null;
  landing_url: string | null;
  conversion_event_id: string | null;
  targeting: {
    countries: string[];
    age_min: number | null;
    age_max: number | null;
    gender: 'all' | 'female' | 'male';
    languages: string[];
    interests: string | null;
  };
  placement: { mode: 'automatic' | 'manual'; placements: string[] };
  ad_copy: { headline: string | null; primary_text: string | null; call_to_action: string | null };
  creative_request_ids: string[];
};

export type Plan = Omit<
  PlanInput,
  | 'project_id'
  | 'external_account_ids'
  | 'tracking_link_id'
  | 'conversion_event_id'
  | 'creative_request_ids'
> & {
  id: string;
  client: { id: string; name: string };
  project: { id: string; name: string };
  targets: Array<{
    external_account_id: string;
    provider: string;
    name: string;
    currency: string | null;
  }>;
  tracking_link: { id: string; name: string; destination_url: string } | null;
  destination: string | null;
  conversion: { id: string; name: string } | null;
  creatives: Array<{ id: string; title: string; state: string }>;
  status: PlanStatus;
  editable: boolean;
  version: number;
  status_reason: string | null;
  created_by: string | null;
  submitted_by: string | null;
  submitted_at: string | null;
  updated_at: string;
  missing?: string[];
};

export type PlanOptions = {
  accounts: Array<{
    id: string;
    provider: string;
    name: string;
    currency: string | null;
    status: string;
  }>;
  tracking_links: Array<{
    id: string;
    name: string;
    destination_url: string;
    platform: string | null;
  }>;
  conversions: Array<{ id: string; name: string; normalized_event: string; is_primary: boolean }>;
  creatives: Array<{ id: string; title: string; state: string; provider: string | null }>;
};

export type PreflightState =
  'ready' | 'warning' | 'blocked' | 'awaiting_credentials' | 'blocked_external' | 'unsupported';

export type PreflightCheck = {
  key: string;
  state: PreflightState;
  message: string;
  fix: string | null;
};

export type LaunchPlan = {
  blueprint_id: string;
  version: number;
  state: PreflightState;
  can_create: boolean;
  checks: PreflightCheck[];
  totals: Array<{ currency: string; budget_minor: number }>;
  providers: Array<{
    state: PreflightState;
    checks: PreflightCheck[];
    plan: {
      provider: string;
      external_account_id: string;
      account_name: string;
      account_currency: string | null;
      campaigns: number;
      ad_groups: number;
      ads: number;
      budget_type: 'daily' | 'lifetime';
      budget_minor: number;
      currency: string;
      starts_at: string;
      ends_at: string | null;
      objective: string | null;
      conversion: string | null;
      destination: string | null;
      initial_state: string;
      delivers_by_default: boolean;
      warnings: string[];
      blocked_fields: string[];
    };
  }>;
};

const KEY = ['campaign-plans'] as const;

export function planToInput(plan: Plan): PlanInput {
  return {
    project_id: plan.project.id,
    name: plan.name,
    objective: plan.objective,
    budget_type: plan.budget_type,
    budget_minor: plan.budget_minor,
    currency: plan.currency,
    starts_at: plan.starts_at,
    ends_at: plan.ends_at,
    external_account_ids: plan.targets.map((target) => target.external_account_id),
    tracking_link_id: plan.tracking_link?.id ?? null,
    landing_url: plan.landing_url,
    conversion_event_id: plan.conversion?.id ?? null,
    targeting: {
      countries: plan.targeting.countries ?? [],
      age_min: plan.targeting.age_min ?? null,
      age_max: plan.targeting.age_max ?? null,
      gender: plan.targeting.gender ?? 'all',
      languages: plan.targeting.languages ?? [],
      interests: plan.targeting.interests ?? null,
    },
    placement: {
      mode: plan.placement.mode ?? 'automatic',
      placements: plan.placement.placements ?? [],
    },
    ad_copy: {
      headline: plan.ad_copy.headline ?? null,
      primary_text: plan.ad_copy.primary_text ?? null,
      call_to_action: plan.ad_copy.call_to_action ?? null,
    },
    creative_request_ids: plan.creatives.map((creative) => creative.id),
  };
}

export function usePlansQuery(filters: { status: string; provider: string }) {
  return useQuery({
    queryKey: [...KEY, 'list', filters],
    queryFn: async () =>
      (
        await api.get<{
          data: Plan[];
          meta: { total: number; counts: Partial<Record<PlanStatus, number>> };
        }>('/campaign-plans', {
          params: { status: filters.status || undefined, provider: filters.provider || undefined },
        })
      ).data,
  });
}

export function usePlanQuery(id: string | undefined) {
  return useQuery({
    queryKey: [...KEY, 'detail', id],
    enabled: id !== undefined,
    queryFn: async () => (await api.get<{ data: Plan }>(`/campaign-plans/${String(id)}`)).data.data,
  });
}

export function usePlanOptionsQuery(projectId: string) {
  return useQuery({
    queryKey: [...KEY, 'options', projectId],
    enabled: projectId !== '',
    queryFn: async () =>
      (
        await api.get<{ data: PlanOptions }>('/campaign-plans/options', {
          params: { project_id: projectId },
        })
      ).data.data,
  });
}

export function useLaunchPlanQuery(
  id: string | undefined,
  version: number | undefined,
  enabled: boolean,
) {
  return useQuery({
    queryKey: [...KEY, 'launch-plan', id, version],
    enabled: enabled && id !== undefined,
    queryFn: async () =>
      (await api.get<{ data: LaunchPlan }>(`/campaign-plans/${String(id)}/launch-plan`)).data.data,
  });
}

export function useSavePlanMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, input }: { id: string | undefined; input: PlanInput }) => {
      await ensureCsrfCookie();
      const response =
        id === undefined
          ? await api.post<{ data: Plan }>('/campaign-plans', input)
          : await api.put<{ data: Plan }>(`/campaign-plans/${id}`, input);
      return response.data.data;
    },
    onSuccess: (plan) => {
      queryClient.setQueryData([...KEY, 'detail', plan.id], plan);
      void queryClient.invalidateQueries({ queryKey: [...KEY, 'list'] });
    },
  });
}

export function usePlanActionMutation(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      action,
      reason,
    }: {
      action: 'submit' | 'return' | 'cancel';
      reason?: string;
    }) => {
      await ensureCsrfCookie();
      return (
        await api.post<{ data: Plan }>(
          `/campaign-plans/${id}/${action}`,
          reason === undefined ? {} : { reason },
        )
      ).data.data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export type LaunchExecution = {
  id: string;
  launch_id: string;
  provider: string;
  external_account_id: string;
  attempt: number;
  status:
    | 'queued'
    | 'creating'
    | 'created'
    | 'verified'
    | 'readback_mismatch'
    | 'failed'
    | 'unknown'
    | 'skipped'
    | 'blocked_external';
  source: 'live' | 'sandbox' | null;
  provider_ids: { campaign: string | null; ad_group: string | null; ads: string[] };
  readback: { found: boolean; mismatches: string[] } | null;
  failure_classification: string | null;
  message: string | null;
  may_retry: boolean;
  may_reconcile: boolean;
  activation_status:
    | 'pending'
    | 'activating'
    | 'sending'
    | 'active'
    | 'failed'
    | 'unknown'
    | 'blocked_external'
    | null;
  activation_message: string | null;
};

export function useLaunchesQuery(id: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: [...KEY, 'launches', id],
    enabled: enabled && id !== undefined,
    queryFn: async () =>
      (await api.get<{ data: LaunchExecution[] }>(`/campaign-plans/${String(id)}/launches`)).data
        .data,
    /* Creation runs on a worker; while anything is still running, look again. */
    refetchInterval: (query) =>
      (query.state.data ?? []).some((e) => ['queued', 'creating', 'created'].includes(e.status))
        ? 3000
        : false,
  });
}

export function useLaunchActionMutation(planId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (action: 'approve' | 'launch' | 'activation' | 'activate') => {
      await ensureCsrfCookie();
      return (await api.post<{ data: unknown }>(`/campaign-plans/${planId}/${action}`)).data.data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useExecutionActionMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, action }: { id: string; action: 'reconcile' | 'retry' }) => {
      await ensureCsrfCookie();
      return (await api.post<{ data: LaunchExecution }>(`/launch-executions/${id}/${action}`)).data
        .data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}
