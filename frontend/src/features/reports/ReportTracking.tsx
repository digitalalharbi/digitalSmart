import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Badge } from '@/shared/ui/badge';
import { dayOf } from './dates';
import type { ReportSummary } from './types';

/**
 * What the agency can say about a report's life: made, opened, due to lapse, live or frozen.
 *
 * Counts and times, and nothing about a reader. A view is a number and the last time one happened — the same two facts
 * a link has always held — so there is no scroll depth, no time on page, no who. A report that has never been opened
 * says so rather than printing a zero beside "last viewed".
 */
export function ReportTracking({ report }: { report: ReportSummary }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const shared = report.live_share_count + report.frozen_share_count > 0;

  return (
    <dl
      aria-label={t('reports.tracking.label', { name: report.name })}
      className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs"
    >
      <Fact label={t('reports.tracking.created')}>{dayOf(report.created_at, locale)}</Fact>
      <Fact label={t('reports.tracking.views')}>
        {report.view_count === 0 ? '—' : formatNumber(report.view_count, locale)}
      </Fact>
      <Fact label={t('reports.tracking.lastViewed')}>
        {report.last_viewed_at === null
          ? t('reports.tracking.neverOpened')
          : formatRelativeTime(report.last_viewed_at, locale)}
      </Fact>
      <Fact label={t('reports.tracking.expires')}>{dayOf(report.next_expiry_at, locale)}</Fact>
      {!shared ? null : (
        <div className="col-span-2 flex flex-wrap gap-1">
          {report.live_share_count === 0 ? null : (
            <Badge tone="neutral">
              {t('reports.tracking.live', { count: report.live_share_count })}
            </Badge>
          )}
          {report.frozen_share_count === 0 ? null : (
            <Badge tone="neutral">
              {t('reports.tracking.frozen', { count: report.frozen_share_count })}
            </Badge>
          )}
        </div>
      )}
    </dl>
  );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col">
      <dt className="text-content-tertiary ds-overline truncate">{label}</dt>
      <dd className="text-content truncate">{children}</dd>
    </div>
  );
}
