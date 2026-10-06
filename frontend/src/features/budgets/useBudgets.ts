import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  approveBudget,
  archiveBudget,
  createBudget,
  deleteBudget,
  fetchAlerts,
  fetchBudget,
  fetchBudgets,
  fetchPacing,
  runForecast,
  saveAllocations,
  updateBudget,
} from './api';
import type { AllocationDraft, BudgetDraft, BudgetPayload, ForecastInput } from './types';

export function useBudgetsQuery(
  params: { status?: string[]; client_id?: string | null } = {},
  enabled = true,
) {
  return useQuery({
    queryKey: queryKeys.budgets.list(params),
    queryFn: () => fetchBudgets(params),
    enabled,
    staleTime: 30_000,
  });
}

export function useBudgetQuery(id: string | null) {
  return useQuery({
    queryKey: queryKeys.budgets.detail(id ?? 'none'),
    queryFn: () => fetchBudget(id ?? ''),
    enabled: id !== null,
    staleTime: 30_000,
  });
}

/**
 * Pacing for one budget.
 *
 * A shorter stale time than the budget itself: the commitment changes when somebody
 * changes it, while the spend behind the pacing moves every time a sync lands.
 */
export function useBudgetPacingQuery(id: string | null) {
  return useQuery({
    queryKey: queryKeys.budgets.pacing(id ?? 'none'),
    queryFn: () => fetchPacing(id ?? ''),
    enabled: id !== null,
    staleTime: 15_000,
  });
}

export function useBudgetAlertsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.budgets.alerts(),
    queryFn: fetchAlerts,
    enabled,
    staleTime: 15_000,
  });
}

/**
 * A forecast.
 *
 * Keyed by its whole input because it is a pure function of it: asking the same
 * question twice gets the cached answer, and changing one assumption gets a fresh one.
 */
export function useForecastQuery(input: ForecastInput | null) {
  return useQuery({
    queryKey: queryKeys.budgets.forecast(input ?? {}),
    queryFn: () => runForecast(input ?? { providers: [] }),
    enabled: input !== null,
    staleTime: 60_000,
  });
}

/**
 * Everything that can change a budget invalidates the list, the detail *and* the
 * pacing.
 *
 * Pacing included, deliberately: approving a budget is what makes it paced at all, and
 * a reallocation changes every allocation row on the pacing table. Invalidating two of
 * the three is how a reader sees a fresh commitment beside a stale variance.
 */
function useBudgetMutation<TArgs>(mutationFn: (args: TArgs) => Promise<BudgetPayload | void>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (budget) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.budgets.all() });

      if (budget !== undefined && budget !== null) {
        queryClient.setQueryData(queryKeys.budgets.detail(budget.id), budget);
      }
    },
  });
}

export function useSaveBudget() {
  return useBudgetMutation(({ id, draft }: { id: string | null; draft: BudgetDraft }) =>
    id === null ? createBudget(draft) : updateBudget(id, draft),
  );
}

export function useApproveBudget() {
  return useBudgetMutation((id: string) => approveBudget(id));
}

export function useArchiveBudget() {
  return useBudgetMutation((id: string) => archiveBudget(id));
}

export function useDeleteBudget() {
  return useBudgetMutation((id: string) => deleteBudget(id));
}

export function useSaveAllocations() {
  return useBudgetMutation(({ id, allocations }: { id: string; allocations: AllocationDraft[] }) =>
    saveAllocations(id, allocations),
  );
}
