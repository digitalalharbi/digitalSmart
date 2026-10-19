import { useTranslation } from 'react-i18next';
import { CheckCircle2, ChevronDown } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { Card } from '@/shared/ui/card';
import { DonutChart } from '@/shared/ui/viz';
import type { TrackingIssue, TrackingSeverity } from './types';

const DOT: Record<TrackingSeverity, string> = {
  critical: 'var(--ds-danger-500)',
  warning: 'var(--ds-warning-500)',
  info: 'var(--ds-neutral-400)',
};

const ORDER: TrackingSeverity[] = ['critical', 'warning', 'info'];

/**
 * Formats one piece of evidence for reading.
 *
 * Dates and lists are the two shapes that actually appear, and a raw JSON blob in front of a
 * media buyer is evidence in name only.
 */
function formatEvidence(value: unknown, locale: string): string {
  if (Array.isArray(value)) {
    return value.map((entry) => formatEvidence(entry, locale)).join(', ');
  }

  if (value === null || value === undefined) {
    return '—';
  }

  if (typeof value === 'string') {
    // An ISO timestamp reads as a date; anything else is already a name or an id.
    const parsed = Date.parse(value);

    return /^\d{4}-\d{2}-\d{2}T/.test(value) && !Number.isNaN(parsed)
      ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(parsed)
      : value;
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  /*
   * An object in the evidence is a shape this component has not been taught to read, and
   * `[object Object]` on the page is worse than nothing: it looks like a bug in the product rather
   * than a gap in the formatting. The dash says "no readable evidence here" honestly.
   */
  return '—';
}

/** Identical issues, once, with how many there are — five clients missing the same conversion is one finding. */
function merged(group: TrackingIssue[]): Array<[TrackingIssue, number]> {
  const out = new Map<string, [TrackingIssue, number]>();

  for (const issue of group) {
    const seen = out.get(issue.summary);
    out.set(issue.summary, [seen?.[0] ?? issue, (seen?.[1] ?? 0) + 1]);
  }

  return [...out.values()];
}

/**
 * What is wrong with a client's measurement, as a picture first and sentences only on request.
 *
 * Fifty issues used to be a hundred paragraphs: each one a sentence and a remedy. Now the top of the list is the
 * count by severity as a ring, then the issues **grouped by kind** — "Not read yet · 12" — and each issue is one
 * line: a severity dot and what it is about. The remedy and the evidence are one click away on that line, and still
 * in the page: the evidence is what lets somebody disagree, which is the only reason they will trust the list when
 * it is right.
 *
 * An empty list is a real state with its own words: silence is the goal, so it is said out loud.
 */
export function IssueList({ issues }: { issues: TrackingIssue[] }) {
  const { t, i18n } = useTranslation();

  if (issues.length === 0) {
    return (
      <Card className="flex items-center gap-3 p-6">
        <CheckCircle2 aria-hidden className="text-success size-6 shrink-0" />
        <div>
          <p className="text-content text-sm font-medium">{t('tracking.issues.noneTitle')}</p>
          <p className="text-content-secondary mt-1 text-sm">{t('tracking.issues.noneBody')}</p>
        </div>
      </Card>
    );
  }

  const bySeverity = ORDER.map((severity) => ({
    severity,
    count: issues.filter((i) => i.severity === severity).length,
  }));
  const groups = new Map<string, TrackingIssue[]>();

  for (const issue of issues) {
    groups.set(issue.code, [...(groups.get(issue.code) ?? []), issue]);
  }

  const ordered = [...groups.entries()].sort(
    ([, a], [, b]) =>
      ORDER.indexOf(a[0]?.severity ?? 'info') - ORDER.indexOf(b[0]?.severity ?? 'info') ||
      b.length - a.length,
  );

  return (
    <div className="flex flex-col gap-4">
      <div className="bg-surface flex flex-wrap items-center gap-6 rounded-3xl p-5 shadow-xs">
        <DonutChart
          legend={false}
          size={88}
          thickness={12}
          label={t('tracking.issues.title')}
          centre={{ value: String(issues.length) }}
          segments={bySeverity.map(({ severity, count }) => ({
            key: severity,
            label: severity,
            value: count,
            colour: DOT[severity],
          }))}
        />
        <ul className="flex flex-wrap gap-3" aria-label={t('tracking.issues.title')}>
          {bySeverity
            .filter(({ count }) => count > 0)
            .map(({ severity, count }) => (
              <li
                key={severity}
                className="bg-surface-sunken flex items-center gap-2.5 rounded-2xl px-4 py-2.5"
              >
                <span
                  aria-hidden
                  className="size-2.5 rounded-full"
                  style={{ background: DOT[severity] }}
                />
                <span className="ds-numeric text-content text-xl font-semibold">{count}</span>
                <span className="text-content-secondary text-sm">
                  {issues.find((i) => i.severity === severity)?.severity_label}
                </span>
              </li>
            ))}
        </ul>
      </div>

      {ordered.map(([code, group]) => (
        <section
          key={code}
          aria-label={t(`tracking.issueKind.${code}`, { defaultValue: code.replace(/_/g, ' ') })}
          className="bg-surface flex flex-col gap-2 rounded-3xl p-4 shadow-xs"
        >
          <header className="flex items-center justify-between gap-3 px-1">
            <span className="flex items-center gap-2">
              <span
                aria-hidden
                className="size-2.5 rounded-full"
                style={{ background: DOT[group[0]?.severity ?? 'info'] }}
              />
              <h3 className="text-content text-sm font-semibold">
                {t(`tracking.issueKind.${code}`, { defaultValue: code.replace(/_/g, ' ') })}
              </h3>
            </span>
            <span className="bg-surface-sunken text-content-secondary ds-numeric rounded-full px-2.5 py-0.5 text-xs font-semibold">
              {group.length}
            </span>
          </header>
          <ul className="flex flex-col">
            {merged(group).map(([issue, count], index) => (
              <li
                key={`${issue.code}-${issue.subject ?? index}-${issue.summary}`}
                className="border-line-subtle border-t first:border-t-0"
              >
                <details className="group/issue">
                  <summary className="flex cursor-pointer list-none items-center gap-2 px-1 py-2.5 text-sm [&::-webkit-details-marker]:hidden">
                    <span
                      className="text-content min-w-0 flex-1 truncate"
                      dir="auto"
                      title={issue.summary}
                    >
                      {issue.summary}
                    </span>
                    {count > 1 ? (
                      <span className="bg-surface-sunken text-content-secondary ds-numeric shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold">
                        ×{count}
                      </span>
                    ) : null}
                    <ChevronDown
                      aria-hidden
                      className="text-content-tertiary size-4 shrink-0 transition-transform group-open/issue:rotate-180"
                    />
                  </summary>
                  <div className={cn('flex flex-col gap-2 px-1 pb-3')}>
                    {issue.remedy === null ? null : (
                      <p className="bg-accent-subtle text-content-brand rounded-xl px-3 py-2 text-sm">
                        {issue.remedy}
                      </p>
                    )}
                    {Object.keys(issue.evidence).length === 0 ? null : (
                      <dl className="flex flex-wrap gap-x-6 gap-y-1">
                        {Object.entries(issue.evidence).map(([key, value]) => (
                          <div key={key} className="flex gap-1.5 text-xs">
                            <dt className="text-content-tertiary">
                              {t(`tracking.evidence.${key}`, {
                                defaultValue: key.replace(/_/g, ' '),
                              })}
                            </dt>
                            <dd className="text-content-secondary font-medium">
                              {formatEvidence(value, i18n.language)}
                            </dd>
                          </div>
                        ))}
                      </dl>
                    )}
                  </div>
                </details>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
