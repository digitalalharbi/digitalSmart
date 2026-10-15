import { Download, FileSpreadsheet, FileText, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/shared/ui/button';
import { exportDownloadUrl } from './api';
import {
  useExportsQuery,
  useRequestExport,
  useScheduleMutations,
  useSchedulesQuery,
} from './useReports';
import type { ExportCadence, ExportJob, ExportKind } from './types';

type ExportsPanelProps = {
  reportId: string;
};

const CADENCES: ExportCadence[] = ['daily', 'weekly', 'monthly'];

const KINDS: { kind: ExportKind; icon: typeof FileText; label: string }[] = [
  { kind: 'report_csv', icon: FileText, label: 'reports.exports.csv' },
  { kind: 'report_xlsx', icon: FileSpreadsheet, label: 'reports.exports.xlsx' },
];

/**
 * Files made in the background.
 *
 * Offered beside the direct downloads rather than instead of them: a small report is quicker to fetch than to
 * wait for, a wide one across many projects is not, and the reader is the one who knows which they asked for.
 * Only this report's exports are listed, and only the reader's own.
 */
export function ExportsPanel({ reportId }: ExportsPanelProps) {
  const { t } = useTranslation();
  const exports = useExportsQuery();
  const request = useRequestExport();
  const mine = (exports.data ?? []).filter((job) => job.subject_id === reportId).slice(0, 5);
  const schedules = useSchedulesQuery(reportId);
  const { create: addSchedule, remove: removeSchedule } = useScheduleMutations(reportId);

  return (
    <section aria-labelledby="exports-heading" className="flex flex-col gap-3">
      <div>
        <h3 id="exports-heading" className="text-content text-sm font-semibold">
          {t('reports.exports.title')}
        </h3>
        <p className="text-content-tertiary text-xs">{t('reports.exports.hint')}</p>
      </div>

      <div className="flex flex-wrap gap-2">
        {KINDS.map(({ kind, icon: Icon, label }) => (
          <Button
            key={kind}
            variant="secondary"
            size="sm"
            disabled={request.isPending}
            onClick={() => request.mutate({ kind, reportId })}
          >
            <Icon aria-hidden className="size-3.5" />
            {t(label)}
          </Button>
        ))}
      </div>

      {request.isError ? (
        <p role="alert" className="text-danger text-xs">
          {t('reports.exports.requestFailed')}
        </p>
      ) : null}

      {mine.length === 0 ? null : (
        <ul className="flex flex-col gap-1.5" aria-live="polite">
          {mine.map((job) => (
            <ExportRow key={job.id} job={job} />
          ))}
        </ul>
      )}

      <div className="border-line-subtle flex flex-col gap-2 border-t pt-3">
        <div>
          <h4 className="text-content text-xs font-semibold">{t('reports.exports.repeat')}</h4>
          <p className="text-content-tertiary text-xs">{t('reports.exports.repeatHint')}</p>
        </div>

        <div className="flex flex-wrap gap-2">
          {CADENCES.map((cadence) => (
            <Button
              key={cadence}
              variant="ghost"
              size="sm"
              disabled={addSchedule.isPending}
              onClick={() => addSchedule.mutate({ kind: 'report_xlsx', cadence })}
            >
              {t(`reports.exports.cadence.${cadence}`)}
            </Button>
          ))}
        </div>

        {(schedules.data ?? []).length === 0 ? null : (
          <ul className="flex flex-col gap-1.5">
            {(schedules.data ?? []).map((schedule) => (
              <li
                key={schedule.id}
                className="border-line-subtle flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm"
              >
                <span className="min-w-0">
                  <span className="text-content block">
                    {t(`reports.exports.cadence.${schedule.cadence}`)} ·{' '}
                    {t(`reports.exports.kind.${schedule.kind}`)}
                  </span>
                  <span className="text-content-tertiary block text-xs">
                    {schedule.is_halted
                      ? (schedule.last_error ?? t('reports.exports.halted'))
                      : t('reports.exports.next', {
                          when: new Date(schedule.next_run_at ?? '').toLocaleString(),
                        })}
                  </span>
                </span>
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={removeSchedule.isPending}
                  onClick={() => removeSchedule.mutate(schedule.id)}
                >
                  {t('reports.exports.stop')}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

function ExportRow({ job }: { job: ExportJob }) {
  const { t } = useTranslation();

  return (
    <li className="border-line-subtle flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm">
      <span className="min-w-0 truncate">
        {job.filename ?? t(`reports.exports.kind.${job.kind}`)}
      </span>

      {job.status === 'ready' ? (
        <a
          href={exportDownloadUrl(job.id)}
          className="text-accent inline-flex shrink-0 items-center gap-1.5 text-xs font-medium hover:underline"
        >
          <Download aria-hidden className="size-3.5" />
          {t('reports.exports.download')}
        </a>
      ) : job.status === 'failed' ? (
        <span className="text-danger inline-flex shrink-0 items-center gap-1.5 text-xs">
          <TriangleAlert aria-hidden className="size-3.5" />
          {job.error ?? t('reports.exports.failed')}
        </span>
      ) : (
        <span className="text-content-tertiary inline-flex shrink-0 items-center gap-1.5 text-xs">
          <LoaderCircle aria-hidden className="size-3.5 motion-safe:animate-spin" />
          {t(`reports.exports.status.${job.status}`)}
        </span>
      )}
    </li>
  );
}
