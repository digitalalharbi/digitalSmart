import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { ReportView } from '@/features/reports';
import { usePortalReportQuery, usePortalReportsQuery } from './usePortal';

/**
 * The reports written for this client.
 *
 * `ReportView` is reused rather than re-implemented, and it is the same component the agency reads a report in.
 * That is deliberate: the client-safe render is a different *document* — the server never loads the agency's
 * commentary on this path — but it should not be a differently built page, because two renderers of one report
 * are two chances to disagree about a figure.
 *
 * No export link. `ReportView` omits it when no href is passed, and there is no CSV route behind a portal read.
 */
export function PortalReportsPanel({ clientId }: { clientId: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [open, setOpen] = useState<string | null>(null);

  const reports = usePortalReportsQuery(clientId);
  const report = usePortalReportQuery(clientId, open);

  if (reports.isPending) {
    return <Skeleton className="h-48 w-full" />;
  }

  const list = reports.data ?? [];

  if (list.length === 0) {
    return <EmptyState title={t('myPortal.reports.empty')} description="" />;
  }

  if (open !== null) {
    return (
      <div className="flex flex-col gap-4">
        <span>
          <Button variant="ghost" size="sm" onClick={() => setOpen(null)}>
            {t('myPortal.reports.back')}
          </Button>
        </span>

        {report.isPending ? (
          <Skeleton className="h-96 w-full" />
        ) : report.data === undefined ? null : (
          <ReportView report={report.data} />
        )}
      </div>
    );
  }

  return (
    <ul className="grid gap-3 md:grid-cols-2">
      {list.map((summary) => (
        <li
          key={summary.id}
          className="bg-surface border-line-subtle flex flex-col gap-2 rounded-2xl border p-4"
        >
          <span className="text-content text-sm font-medium">{summary.name}</span>
          <span className="text-content-tertiary text-xs">{summary.window_label}</span>
          {summary.description === null ? null : (
            <span className="text-content-secondary text-sm">{summary.description}</span>
          )}
          <span className="flex flex-wrap items-center gap-1.5">
            {/* So a reader can tell a frozen copy from one that recalculates every time they open it. */}
            {summary.snapshot_count > 0 ? (
              <Badge tone="neutral">
                {t('reports.card.snapshots', { count: summary.snapshot_count })}
              </Badge>
            ) : null}
            <span className="text-content-tertiary text-xs">
              {new Date(summary.updated_at).toLocaleDateString(locale)}
            </span>
          </span>
          <span>
            <Button variant="secondary" size="sm" onClick={() => setOpen(summary.id)}>
              {t('myPortal.reports.open')}
            </Button>
          </span>
        </li>
      ))}
    </ul>
  );
}
