import { useState, type ReactNode } from 'react';
import { CalendarClock, Download, Snowflake, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { InfoHint } from '@/shared/ui/info-hint';
import { SourceBadge } from '@/shared/ui/source-badge';
import type { MetricSourceValue } from '@/shared/metrics';
import { ReportHighlights } from './ReportHighlights';
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
  /** The same figures, typed. Offered beside the CSV rather than instead of it: a CSV is what a script reads. */
  xlsxHref?: string | undefined;
  /** A row of controls under the header — the shared page's filters. Absent everywhere else. */
  filterBar?: ReactNode | undefined;
  /** Whether a visitor's filters are on, which changes what an empty report means: nothing matches, not nothing recorded. */
  isFiltered?: boolean | undefined;
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
export function ReportView({
  report,
  csvHref,
  xlsxHref,
  filterBar,
  isFiltered = false,
}: ReportViewProps) {
  const { t, i18n } = useTranslation();
  /*
   * Two readings of one report. Executive is the answer — the scorecards and the trends; detailed is the
   * working: every breakdown and table. A client-safe report opens as the first, because the people it was
   * made for read the answer, and an internal one as the second, because the people who made it check the work.
   */
  const [level, setLevel] = useState<'executive' | 'detailed'>(
    report.audience === 'client_safe' ? 'executive' : 'detailed',
  );
  const headline = report.sections.filter(
    (section) => section.kind === 'scorecard' || section.kind === 'trend',
  );
  const canSummarise = headline.length > 0 && headline.length < report.sections.length;
  const shown = level === 'executive' && canSummarise ? headline : report.sections;
  /*
   * A figure's own days, taken from the report's trend section when it has one for that metric. Never computed here:
   * the report already carries the series, and a second derivation is a second number.
   */
  const sparks: Record<string, Array<number | null>> = {};
  for (const section of report.sections) {
    if (section.kind !== 'trend') {
      continue;
    }
    for (const metric of section.metrics) {
      sparks[metric] ??= section.series.map((point) => point.metrics.values[metric]?.value ?? null);
    }
  }

  return (
    <article className="flex flex-col gap-5">
      <header className="bg-surface flex flex-col gap-4 rounded-3xl p-5 shadow-xs">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            {/* Initials, not a logo: nothing here stores one, and a mark that looked like a brand asset would be a claim. */}
            <span
              aria-hidden
              className="bg-surface-sunken text-content-secondary inline-flex size-12 shrink-0 items-center justify-center rounded-2xl text-base font-semibold"
            >
              {(report.client_name ?? report.name).slice(0, 2).toUpperCase()}
            </span>
            <div className="min-w-0">
              <div className="flex items-center gap-1">
                <h2 className="text-content text-xl font-semibold" dir="auto">
                  {report.name}
                </h2>
                {report.description === null ? null : <InfoHint text={report.description} />}
              </div>
              <p className="text-content-secondary mt-0.5 truncate text-sm" dir="auto">
                {report.client_name ?? t('reports.view.allClients')}
              </p>
            </div>
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
                className="text-content-brand inline-flex items-center gap-1.5 text-sm hover:underline"
              >
                <Download aria-hidden className="size-3.5" />
                {t('reports.view.csv')}
              </a>
            )}
            {xlsxHref === undefined ? null : (
              <a
                href={xlsxHref}
                className="text-content-brand inline-flex items-center gap-1.5 text-sm hover:underline"
              >
                <Download aria-hidden className="size-3.5" />
                {t('reports.view.xlsx')}
              </a>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span className="bg-surface-sunken text-content-secondary ds-numeric rounded-full px-3 py-1 font-medium">
            <bdi dir="ltr">
              {report.period.from} – {report.period.until}
            </bdi>
            {' · '}
            {t('reports.view.days', { count: report.period.days })}
          </span>

          {report.comparison.was_requested && report.comparison.is_comparable ? (
            <span className="bg-surface-sunken text-content-secondary inline-flex items-center gap-1 rounded-full px-3 py-1 font-medium">
              {t('reports.view.vs', {
                label: report.comparison.label.toLocaleLowerCase(i18n.language),
              })}
              <InfoHint
                text={t('reports.view.comparedWith', {
                  label: report.comparison.label,
                  from: report.comparison.from ?? '',
                  until: report.comparison.until ?? '',
                })}
              />
            </span>
          ) : null}

          {report.period.is_incomplete ? (
            <Badge tone="warning" className="gap-1">
              {t('reports.view.running')}
              <InfoHint text={t('reports.view.incomplete')} />
            </Badge>
          ) : null}

          <span className="text-content-tertiary ms-auto">
            {t('reports.view.renderedAt', {
              at: new Date(report.rendered_at).toLocaleString(i18n.language),
            })}
          </span>
        </div>

        {/*
          The sentence that replaces the percentages. It stays in the open: a reader who finds no comparison and no
          reason does the arithmetic themselves against whatever period they pick.
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

        {/*
          The agency's own commentary. Absent from a client-safe render because it was never read on that path — see
          `RenderReport` — so this only ever appears on an internal one.
        */}
        {report.internal_note === null ? null : (
          <Alert tone="info">
            {t('reports.view.internalNote', { note: report.internal_note })}
          </Alert>
        )}
      </header>

      {filterBar}

      {report.is_empty || (isFiltered && report.sections.every((section) => section.is_empty)) ? (
        <EmptyState
          title={isFiltered ? t('sharedReport.filters.empty') : t('reports.view.emptyTitle')}
          description={
            isFiltered ? t('sharedReport.filters.emptyBody') : t('reports.view.emptyBody')
          }
        />
      ) : (
        <div className="flex flex-col gap-6">
          {canSummarise ? (
            <div
              className="bg-surface-sunken flex w-fit rounded-lg p-0.5"
              role="group"
              aria-label={t('reports.level.label')}
            >
              {(['executive', 'detailed'] as const).map((key) => (
                <Button
                  key={key}
                  size="sm"
                  variant={level === key ? 'secondary' : 'ghost'}
                  aria-pressed={level === key}
                  onClick={() => setLevel(key)}
                >
                  {t(`reports.level.${key}`)}
                </Button>
              ))}
            </div>
          ) : null}
          <ReportHighlights
            report={report}
            onViewDetails={
              level === 'executive' && canSummarise ? () => setLevel('detailed') : undefined
            }
          />
          {shown.map((section, index) => (
            <ReportSectionView key={`${section.kind}-${index}`} section={section} sparks={sparks} />
          ))}
        </div>
      )}
    </article>
  );
}
