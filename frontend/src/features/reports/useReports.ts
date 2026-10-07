import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  createReport,
  deleteReport,
  fetchReport,
  fetchReportDraft,
  fetchReportVocabulary,
  fetchReports,
  fetchShares,
  fetchSnapshots,
  revokeShare,
  shareReport,
  takeSnapshot,
  updateReport,
} from './api';

export function useReportVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.reports.vocabulary(),
    queryFn: fetchReportVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

export function useReportsQuery(clientId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.reports.list(clientId),
    queryFn: () => fetchReports(clientId),
    enabled,
    staleTime: 30_000,
  });
}

/**
 * A rendered report.
 *
 * Held for a minute, which is longer than the boards: a report is read rather than worked, and re-running an
 * aggregation per section because somebody switched tabs and came back is work nobody asked for. The render carries
 * its own timestamp, so a reader can always see how old the figures are.
 */
export function useRenderedReportQuery(reportId: string | null) {
  return useQuery({
    queryKey: queryKeys.reports.rendered(reportId ?? ''),
    queryFn: () => fetchReport(reportId ?? ''),
    enabled: reportId !== null,
    staleTime: 60_000,
  });
}

export function useReportDraftQuery(reportId: string | null) {
  return useQuery({
    queryKey: queryKeys.reports.draft(reportId ?? ''),
    queryFn: () => fetchReportDraft(reportId ?? ''),
    enabled: reportId !== null,
    staleTime: 30_000,
  });
}

export function useSnapshotsQuery(reportId: string | null) {
  return useQuery({
    queryKey: queryKeys.reports.snapshots(reportId ?? ''),
    queryFn: () => fetchSnapshots(reportId ?? ''),
    enabled: reportId !== null,
    staleTime: 30_000,
  });
}

export function useSharesQuery(reportId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.reports.shares(reportId ?? ''),
    queryFn: () => fetchShares(reportId ?? ''),
    enabled: reportId !== null && enabled,
    staleTime: 15_000,
  });
}

function useReportMutation<TInput, TResult>(mutationFn: (input: TInput) => Promise<TResult>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      /*
       * The whole module. A saved definition changes what a render returns, a snapshot changes a count on the list,
       * and a share changes the figure somebody checks before saying nobody outside can see this.
       */
      void client.invalidateQueries({ queryKey: queryKeys.reports.all() });
    },
  });
}

export function useCreateReportMutation() {
  return useReportMutation(createReport);
}

export function useUpdateReportMutation() {
  return useReportMutation(updateReport);
}

export function useDeleteReportMutation() {
  return useReportMutation(deleteReport);
}

export function useSnapshotMutation() {
  return useReportMutation(takeSnapshot);
}

export function useShareMutation() {
  return useReportMutation(shareReport);
}

export function useRevokeShareMutation() {
  return useReportMutation(revokeShare);
}
