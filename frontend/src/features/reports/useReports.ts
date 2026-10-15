import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  createReport,
  deleteReport,
  createSchedule,
  deleteSchedule,
  fetchExports,
  fetchSchedules,
  fetchReport,
  fetchReportDraft,
  fetchReportVocabulary,
  fetchReports,
  fetchShares,
  fetchSnapshots,
  requestExport,
  revokeShare,
  shareReport,
  takeSnapshot,
  updateReport,
} from './api';
import type { ExportCadence, ExportKind } from './types';

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

/**
 * The reader's exports, re-read every few seconds **only while one is still being made**.
 *
 * A fixed interval would poll a quiet account for ever; stopping once nothing is queued or running means an idle
 * page costs nothing and a waiting one updates without a click.
 */
export function useExportsQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.exports.mine(),
    queryFn: fetchExports,
    enabled,
    staleTime: 5_000,
    refetchInterval: (query) =>
      (query.state.data ?? []).some((job) => job.status === 'queued' || job.status === 'running')
        ? 3_000
        : false,
  });
}

export function useRequestExport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ kind, reportId }: { kind: ExportKind; reportId: string }) =>
      requestExport(kind, reportId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.exports.mine() }),
  });
}

export function useSchedulesQuery(reportId: string) {
  return useQuery({
    queryKey: queryKeys.exports.schedules(reportId),
    queryFn: () => fetchSchedules(reportId),
    staleTime: 30_000,
  });
}

export function useScheduleMutations(reportId: string) {
  const queryClient = useQueryClient();
  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: queryKeys.exports.schedules(reportId) });

  return {
    create: useMutation({
      mutationFn: (input: { kind: ExportKind; cadence: ExportCadence }) =>
        createSchedule({ reportId, ...input }),
      onSuccess: refresh,
    }),
    remove: useMutation({ mutationFn: deleteSchedule, onSuccess: refresh }),
  };
}
