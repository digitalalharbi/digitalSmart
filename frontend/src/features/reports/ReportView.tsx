import { CalendarClock, Download, Snowflake, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { EmptyState } from '@/shared/ui/empty-state';
import { SourceBadge } from '@/shared/ui/source-badge';
import type { MetricSourceValue } from '@/shared/metrics';
import { ReportSectionView } from './ReportSectionView';
import type { RenderedReport } from './types';

type ReportViewProps = {
  report: RenderedReport;
  /**
   * Omitted on the shared-link page, which has no CSV endpoint behind it.
   *
   * A visitor with a link holds a token, not an account, and a download route that accepted one would be a second
   * unauthenticated read to get right. The export stays inside the workspace.
   */
  csvHref?: string | undefined;
};

/**
 * A rendered report.
 *
 * The header is the honest part, and it carries four things a reader would otherwise have to infer.
 *
 * **When it was rendered**, always. A figure with no timestamp is a figure somebody will quote next month.
 *
 * **Whether it is live or frozen.** A snapshot shows what was true when it was taken; a live report recomputes. A
 * link that quietly recomputed would make "the report said 1.2 million" unanswerable.
 *
 * **Whether the period is still running.** A month-to-date report is legitimate and is the one most likely to be
 * compared against a whole month.
 *
 * **Why there are no change figures, when there are none.** The sentence goes where the percentages would have been,
 * because a reader who sees no comparison and no explanation assumes the feature is broken and does the arithmetic
 * themselves — against whatever period they happen to pick.
 */
export function ReportView({ report, csvHref }: ReportViewProps) {
  const { t } = useTranslation();

  return (
    <article className="flex flex-col gap-5">
      <header className="flex flex-col gap-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-content text-xl font-semibold">{report.name}</h2>
            <p className="text-content-secondary mt-1 text-sm">
              {report.client_name ?? t('reports.view.allClients')}
              {' · '}
              {report.period.from} – {report.period.until}
              {' · '}
              {t('reports.view.days', { count: report.period.days })}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* The source state of the weakest section anywhere in it. */}
            <SourceBadge source={report.source as MetricSourceValue} />
            <Badge tone={report.mode === 'snapshot' ? 'accent' : 'neutral'}>
              {report.mode === 'snapshot' ? (
                <Snowflake aria-hidden className="me-1 size-3" />
              ) : (
                <CalendarClock aria-hidden className="me-1 size-3" />
              )}
              {report.mode_label}
            </Badge>
            {csvHref === undefined ? null : (
              <a
                href={csvHref}
                className="text-accent inline-flex items-center gap-1.5 text-sm hover:underline"
              >
                <Download aria-hidden className="size-3.5" />
                {t('reports.view.csv')}
              </a>
            )}
          </div>
        </div>

        {report.description === null ? null : (
          <p className="text-content-secondary text-sm">{report.description}</p>
        )}

        {report.period.is_incomplete ? (
          <Alert tone="info">{t('reports.view.incomplete')}</Alert>
        ) : null}

        {/*
          The sentence that replaces the percentages. Said once, above the sections, because it is true of every
          change figure in the document — and said at all, because a reader who finds no comparison and no reason
          does the arithmetic themselves against whatever period they pick.
        */}
        {report.comparison.was_requested && !report.comparison.is_comparable ? (
          <Alert tone="warning" className="flex items-start gap-2">
            <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>
              {t('reports.view.notComparable', { comparison: report.comparison.label })}{' '}
              {report.comparison.reason}
            </span>
          </Alert>
        ) : null}

        {report.comparison.was_requested && report.comparison.is_comparable ? (
          <p className="text-content-tertiary text-sm">
            {t('reports.view.comparedWith', {
              label: report.comparison.label,
              from: report.comparison.from ?? '',
              until: report.comparison.until ?? '',
            })}
          </p>
        ) : null}

        {/*
          The agency's own commentary. Absent from a client-safe render because it was never read on that path — see
          `RenderReport` — so this only ever appears on an internal one.
        */}
        {report.internal_note === null ? null : (
          <Alert tone="info">
            {t('reports.view.internalNote', { note: report.internal_note })}
          </Alert>
        )}

        <p className="text-content-tertiary text-xs">
          {t('reports.view.renderedAt', { at: new Date(report.rendered_at).toLocaleString() })}
        </p>
      </header>

      {report.is_empty ? (
        <EmptyState
          title={t('reports.view.emptyTitle')}
          description={t('reports.view.emptyBody')}
        />
      ) : (
        <div className="flex flex-col gap-6">
          {report.sections.map((section, index) => (
            <ReportSectionView key={`${section.kind}-${index}`} section={section} />
          ))}
        </div>
      )}
    </article>
  );
}
