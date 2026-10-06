import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { Card } from '@/shared/ui/card';
import type { TrackingIssue, TrackingSeverity } from './types';

const TONE: Record<TrackingSeverity, 'danger' | 'warning' | 'neutral'> = {
  critical: 'danger',
  warning: 'warning',
  info: 'neutral',
};

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

/**
 * What is wrong with a client's measurement, and what to do about it.
 *
 * Three things this component insists on, each of them a product decision rather than a styling
 * one:
 *
 *  - **The remedy is as prominent as the problem.** An issue list without remedies is a list of
 *    complaints, and nobody reads one twice.
 *  - **The evidence is on the page, not behind a tooltip.** A tracking centre that says "purchase
 *    event missing" without saying how it knows is one people stop believing after the first false
 *    alarm — and a false alarm is certain, because the product can only see what a provider
 *    reports. The evidence is what lets somebody disagree, which is the only reason they will
 *    trust it when it is right.
 *  - **An empty list is a real state with its own words.** Silence is the goal here, so it gets
 *    said out loud rather than rendered as a blank area that looks like a loading failure.
 */
export function IssueList({ issues }: { issues: TrackingIssue[] }) {
  const { t, i18n } = useTranslation();

  if (issues.length === 0) {
    return (
      <Card className="p-6">
        <p className="text-content text-sm font-medium">{t('tracking.issues.noneTitle')}</p>
        <p className="text-content-secondary mt-1 text-sm">{t('tracking.issues.noneBody')}</p>
      </Card>
    );
  }

  return (
    <ul className="flex flex-col gap-3">
      {issues.map((issue, index) => (
        <li key={`${issue.code}-${issue.subject ?? index}`}>
          <Card className="p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <p className="text-content text-sm font-medium">{issue.summary}</p>
              <Badge tone={TONE[issue.severity]}>{issue.severity_label}</Badge>
            </div>

            {issue.remedy === null ? null : (
              <p className="text-content-secondary mt-2 text-sm">{issue.remedy}</p>
            )}

            {Object.keys(issue.evidence).length === 0 ? null : (
              <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1">
                {Object.entries(issue.evidence).map(([key, value]) => (
                  <div key={key} className="flex gap-1.5 text-xs">
                    <dt className="text-content-tertiary">
                      {t(`tracking.evidence.${key}`, { defaultValue: key.replace(/_/g, ' ') })}
                    </dt>
                    <dd className="text-content-secondary font-medium">
                      {formatEvidence(value, i18n.language)}
                    </dd>
                  </div>
                ))}
              </dl>
            )}
          </Card>
        </li>
      ))}
    </ul>
  );
}
