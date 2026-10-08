import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import type { RevenueSourceValue } from '@/features/commerce';
import {
  enablePortal,
  fetchMyPortals,
  fetchPortalRevenue,
  fetchPortalTracking,
  fetchPortal,
  fetchPortalPerformance,
  fetchPortalReport,
  fetchPortalReports,
  fetchPortalVocabulary,
  fetchPortals,
  resumePortal,
  setPortalModules,
  suspendPortal,
} from './api';

/* ----------------------------------------------------------------- agency ---- */

export function usePortalVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.portal.vocabulary(),
    queryFn: fetchPortalVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

export function usePortalsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.portal.list(),
    queryFn: fetchPortals,
    enabled,
    staleTime: 30_000,
  });
}

export function usePortalQuery(clientId: string | null) {
  return useQuery({
    queryKey: queryKeys.portal.detail(clientId ?? ''),
    queryFn: () => fetchPortal(clientId ?? ''),
    enabled: clientId !== null,
    staleTime: 30_000,
  });
}

/**
 * Every write invalidates the whole module.
 *
 * A module grant changes the list's exposed count, the detail's record of who exposed what, and what a reader's
 * own page may open. Invalidating one and not the others would leave a screen asserting that a client cannot see
 * something they can.
 */
function useInvalidatePortal(): () => void {
  const client = useQueryClient();

  return () => {
    void client.invalidateQueries({ queryKey: queryKeys.portal.all() });
  };
}

export function useEnablePortalMutation() {
  const invalidate = useInvalidatePortal();

  return useMutation({ mutationFn: enablePortal, onSuccess: invalidate });
}

export function useSuspendPortalMutation() {
  const invalidate = useInvalidatePortal();

  return useMutation({ mutationFn: suspendPortal, onSuccess: invalidate });
}

export function useResumePortalMutation() {
  const invalidate = useInvalidatePortal();

  return useMutation({ mutationFn: resumePortal, onSuccess: invalidate });
}

export function useSetPortalModulesMutation() {
  const invalidate = useInvalidatePortal();

  return useMutation({ mutationFn: setPortalModules, onSuccess: invalidate });
}

/* ----------------------------------------------------------------- client ---- */

export function useMyPortalsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.portal.mine(),
    queryFn: fetchMyPortals,
    enabled,
    staleTime: 30_000,
  });
}

export function usePortalPerformanceQuery(clientId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.portal.performance(clientId ?? ''),
    queryFn: () => fetchPortalPerformance(clientId ?? ''),
    enabled: enabled && clientId !== null,
    staleTime: 60_000,
  });
}

export function usePortalReportsQuery(clientId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.portal.reports(clientId ?? ''),
    queryFn: () => fetchPortalReports(clientId ?? ''),
    enabled: enabled && clientId !== null,
    staleTime: 60_000,
  });
}

export function usePortalReportQuery(clientId: string | null, reportId: string | null) {
  return useQuery({
    queryKey: queryKeys.portal.report(clientId ?? '', reportId ?? ''),
    queryFn: () => fetchPortalReport({ clientId: clientId ?? '', reportId: reportId ?? '' }),
    enabled: clientId !== null && reportId !== null,
    staleTime: 60_000,
  });
}

/**
 * Revenue, where the first call deliberately names no authority.
 *
 * Asking without one comes back as a refusal carrying the sources the surface accepts, which is what the panel
 * builds its chooser from. Nothing is shown until a reader chooses, because a Salla total and a GA4 total are
 * different claims about the same period and picking one for them is how a portal shows one labelled as the other.
 */
export function usePortalRevenueQuery(
  clientId: string | null,
  revenueSource: RevenueSourceValue | null,
  enabled = true,
) {
  return useQuery({
    queryKey: queryKeys.portal.revenue(clientId ?? '', revenueSource),
    queryFn: () => fetchPortalRevenue({ clientId: clientId ?? '', revenueSource }),
    enabled: enabled && clientId !== null,
    staleTime: 60_000,
  });
}

export function usePortalTrackingQuery(clientId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.portal.tracking(clientId ?? ''),
    queryFn: () => fetchPortalTracking(clientId ?? ''),
    enabled: enabled && clientId !== null,
    staleTime: 60_000,
  });
}
