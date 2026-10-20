import { api } from '@/shared/api/client';
import type {
  RenderedReport,
  ExportCadence,
  ExportJob,
  ExportSchedule,
  ExportKind,
  ReportDraft,
  ReportShare,
  ReportSnapshot,
  ReportSummary,
  ReportVocabulary,
  SectionDraft,
} from './types';

export async function fetchReportVocabulary(): Promise<ReportVocabulary> {
  const { data } = await api.get<{ data: ReportVocabulary }>('/reports/vocabulary');

  return data.data;
}

export async function fetchReports(clientId: string | null): Promise<ReportSummary[]> {
  const { data } = await api.get<{ data: ReportSummary[] }>('/reports', {
    params: clientId === null ? {} : { client_id: clientId },
  });

  return data.data;
}

export async function fetchReport(reportId: string): Promise<RenderedReport> {
  const { data } = await api.get<{ data: RenderedReport }>(`/reports/${reportId}`);

  return data.data;
}

/**
 * The definition, for an editor.
 *
 * A separate read from the render on purpose: rendering costs one analytics aggregation per section, and an editor
 * that paid for it on every keystroke would be the slowest screen in the product.
 */
export async function fetchReportDraft(reportId: string): Promise<ReportDraft> {
  const { data } = await api.get<{ data: ReportDraft }>(`/reports/${reportId}/definition`);

  return data.data;
}

type ReportInput = {
  name: string;
  description?: string | null;
  clientId?: string | null;
  window: string;
  customFrom?: string | null;
  customUntil?: string | null;
  comparison: string;
  audience: string;
  internalNote?: string | null;
  sections: SectionDraft[];
};

function body(input: ReportInput): Record<string, unknown> {
  return {
    name: input.name,
    window: input.window,
    comparison: input.comparison,
    audience: input.audience,
    ...(input.description === undefined ? {} : { description: input.description ?? '' }),
    ...(input.clientId ? { client_id: input.clientId } : {}),
    ...(input.customFrom ? { custom_from: input.customFrom } : {}),
    ...(input.customUntil ? { custom_until: input.customUntil } : {}),
    ...(input.internalNote === undefined ? {} : { internal_note: input.internalNote ?? '' }),
    sections: input.sections.map((section) => ({
      kind: section.kind,
      metrics: section.metrics,
      ...(section.title === null ? {} : { title: section.title }),
      ...(section.dimension === null ? {} : { dimension: section.dimension }),
      ...(section.row_limit === null ? {} : { row_limit: section.row_limit }),
      ...(section.revenue_source === null ? {} : { revenue_source: section.revenue_source }),
    })),
  };
}

export async function createReport(input: ReportInput): Promise<ReportDraft> {
  const { data } = await api.post<{ data: ReportDraft }>('/reports', body(input));

  return data.data;
}

export async function updateReport(
  input: ReportInput & { reportId: string },
): Promise<ReportDraft> {
  const { data } = await api.put<{ data: ReportDraft }>(`/reports/${input.reportId}`, body(input));

  return data.data;
}

export async function deleteReport(reportId: string): Promise<void> {
  await api.delete(`/reports/${reportId}`);
}

export async function fetchSnapshots(reportId: string): Promise<ReportSnapshot[]> {
  const { data } = await api.get<{ data: ReportSnapshot[] }>(`/reports/${reportId}/snapshots`);

  return data.data;
}

export async function takeSnapshot(input: {
  reportId: string;
  label?: string;
}): Promise<{ id: string }> {
  const { data } = await api.post<{ data: { id: string } }>(
    `/reports/${input.reportId}/snapshots`,
    input.label === undefined ? {} : { label: input.label },
  );

  return data.data;
}

export async function fetchShares(reportId: string): Promise<ReportShare[]> {
  const { data } = await api.get<{ data: ReportShare[] }>(`/reports/${reportId}/shares`);

  return data.data;
}

export async function shareReport(input: {
  reportId: string;
  days: number;
  snapshotId?: string | null;
  recipientLabel?: string;
}): Promise<{ share: ReportShare; token: string }> {
  const { data } = await api.post<{ data: ReportShare; meta: { token: string } }>(
    `/reports/${input.reportId}/shares`,
    {
      days: input.days,
      ...(input.snapshotId ? { snapshot_id: input.snapshotId } : {}),
      ...(input.recipientLabel === undefined ? {} : { recipient_label: input.recipientLabel }),
    },
  );

  /*
   * The token is in the response once and is never fetched again. The interface shows it once, says so, and offers
   * no way back to it: a link somebody lost is a link they should replace rather than recover.
   */
  return { share: data.data, token: data.meta.token };
}

export async function revokeShare(input: { shareId: string; reason: string }): Promise<void> {
  await api.delete(`/report-shares/${input.shareId}`, { data: { reason: input.reason } });
}

/** The CSV download URL. A plain link, so the browser handles the download rather than JavaScript. */
export function csvUrl(reportId: string): string {
  return `/api/reports/${reportId}/csv`;
}

/**
 * The same report as a spreadsheet.
 *
 * Not a different document: one render, one set of figures. The difference is types — a number arrives in Excel
 * as a number rather than as text somebody retypes, and money carries its currency in the cell's own format.
 */
export function xlsxUrl(reportId: string): string {
  return `/api/reports/${reportId}/xlsx`;
}

/** Ask for a file to be made in the background. Answers at once with a queued job. */
export async function requestExport(kind: ExportKind, subjectId: string): Promise<ExportJob> {
  const { data } = await api.post<{ data: ExportJob }>('/exports', {
    kind,
    subject_id: subjectId,
  });

  return data.data;
}

/** The reader's own exports, newest first. Nobody else's, whatever their role. */
export async function fetchExports(): Promise<ExportJob[]> {
  const { data } = await api.get<{ data: ExportJob[] }>('/exports');

  return data.data;
}

export function exportDownloadUrl(exportId: string): string {
  return `/api/exports/${exportId}/download`;
}

export async function fetchSchedules(reportId: string): Promise<ExportSchedule[]> {
  const { data } = await api.get<{ data: ExportSchedule[] }>('/exports/schedules', {
    params: { report_id: reportId },
  });

  return data.data;
}

export async function createSchedule(input: {
  reportId: string;
  kind: ExportKind;
  cadence: ExportCadence;
}): Promise<ExportSchedule> {
  const { data } = await api.post<{ data: ExportSchedule }>('/exports/schedules', {
    report_id: input.reportId,
    kind: input.kind,
    cadence: input.cadence,
  });

  return data.data;
}

export async function deleteSchedule(scheduleId: string): Promise<void> {
  await api.delete(`/exports/schedules/${scheduleId}`);
}
