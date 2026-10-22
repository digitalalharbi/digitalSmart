import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import { dayOf } from './dates';
import type { CentreShare, CentreSnapshot, ExportSchedule, ReportSummary } from './types';

type Query<T> = { data: T[] | undefined; isPending: boolean; isError: boolean };

function Waiting<T>({
  query,
  label,
  empty,
  children,
}: {
  query: Query<T>;
  label: string;
  empty: { title: string; body: string };
  children: (rows: T[]) => React.ReactNode;
}) {
  const { t } = useTranslation();

  if (query.isPending) {
    return <Skeleton aria-label={label} className="h-32 w-full" />;
  }

  if (query.isError) {
    return <Alert tone="danger">{t('reports.centre.loadFailed')}</Alert>;
  }

  const rows = query.data ?? [];

  return rows.length === 0 ? (
    <EmptyState title={empty.title} description={empty.body} />
  ) : (
    children(rows)
  );
}

/**
 * Live reports: those with a link that recomputes every time it is opened.
 *
 * What an agency needs from this list is the reverse of what a client needs from the link: not the figures, but how
 * many times a door has been used, when it was last, and when it closes.
 */
export function LivePanel({
  reports,
  onOpen,
}: {
  reports: ReportSummary[];
  onOpen: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const live = reports.filter((report) => report.live_share_count > 0);

  return (
    <Waiting
      query={{ data: live, isPending: false, isError: false }}
      label={t('reports.centre.tabs.live')}
      empty={{
        title: t('reports.centre.live.emptyTitle'),
        body: t('reports.centre.live.emptyBody'),
      }}
    >
      {(rows) => (
        <DataTable label={t('reports.centre.tabs.live')} minWidth="48rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('reports.centre.col.report')}</TableHeader>
              <TableHeader>{t('reports.centre.col.client')}</TableHeader>
              <TableHeader align="end">{t('reports.centre.col.links')}</TableHeader>
              <TableHeader align="end">{t('reports.tracking.views')}</TableHeader>
              <TableHeader>{t('reports.tracking.lastViewed')}</TableHeader>
              <TableHeader>{t('reports.tracking.expires')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((report) => (
              <TableRow key={report.id}>
                <TableCell>
                  <Button variant="ghost" size="sm" onClick={() => onOpen(report.id)}>
                    <span dir="auto">{report.name}</span>
                  </Button>
                </TableCell>
                <TableCell label={t('reports.centre.col.client')}>
                  {report.client_name ?? t('reports.view.allClients')}
                </TableCell>
                <TableCell isNumeric align="end" label={t('reports.centre.col.links')}>
                  {formatNumber(report.live_share_count, locale)}
                </TableCell>
                <TableCell isNumeric align="end" label={t('reports.tracking.views')}>
                  {report.view_count === 0 ? '—' : formatNumber(report.view_count, locale)}
                </TableCell>
                <TableCell label={t('reports.tracking.lastViewed')}>
                  {report.last_viewed_at === null
                    ? t('reports.tracking.neverOpened')
                    : formatRelativeTime(report.last_viewed_at, locale)}
                </TableCell>
                <TableCell label={t('reports.tracking.expires')}>
                  {dayOf(report.next_expiry_at, locale)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}
    </Waiting>
  );
}

/** Scheduled reports: the exports somebody asked to have made for them on a rhythm. */
export function ScheduledPanel({
  query,
  reports,
  onOpen,
}: {
  query: Query<ExportSchedule>;
  reports: ReportSummary[];
  onOpen: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const names = new Map(reports.map((report) => [report.id, report.name]));

  return (
    <Waiting
      query={query}
      label={t('reports.centre.tabs.scheduled')}
      empty={{
        title: t('reports.centre.scheduled.emptyTitle'),
        body: t('reports.centre.scheduled.emptyBody'),
      }}
    >
      {(rows) => (
        <DataTable label={t('reports.centre.tabs.scheduled')} minWidth="48rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('reports.centre.col.report')}</TableHeader>
              <TableHeader>{t('reports.centre.col.file')}</TableHeader>
              <TableHeader>{t('reports.centre.col.cadence')}</TableHeader>
              <TableHeader>{t('reports.centre.col.nextRun')}</TableHeader>
              <TableHeader>{t('reports.centre.col.lastRun')}</TableHeader>
              <TableHeader>{t('reports.centre.col.state')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((schedule) => (
              <TableRow key={schedule.id}>
                <TableCell>
                  <Button variant="ghost" size="sm" onClick={() => onOpen(schedule.report_id)}>
                    <span dir="auto">{names.get(schedule.report_id) ?? '—'}</span>
                  </Button>
                </TableCell>
                <TableCell label={t('reports.centre.col.file')}>
                  {t(`reports.exports.kind.${schedule.kind}`, { defaultValue: schedule.kind })}
                </TableCell>
                <TableCell label={t('reports.centre.col.cadence')}>
                  {t(`reports.exports.cadence.${schedule.cadence}`, {
                    defaultValue: schedule.cadence,
                  })}
                </TableCell>
                <TableCell label={t('reports.centre.col.nextRun')}>
                  {dayOf(schedule.next_run_at, locale)}
                </TableCell>
                <TableCell label={t('reports.centre.col.lastRun')}>
                  {schedule.last_run_at === null
                    ? '—'
                    : formatRelativeTime(schedule.last_run_at, locale)}
                </TableCell>
                <TableCell label={t('reports.centre.col.state')}>
                  {schedule.is_halted ? (
                    <Badge tone="warning">{t('reports.centre.halted')}</Badge>
                  ) : (
                    <Badge tone="neutral">{t('reports.centre.running')}</Badge>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}
    </Waiting>
  );
}

/** Every frozen record: what a report said on the day it was taken. */
export function SnapshotsPanel({
  query,
  onOpen,
}: {
  query: Query<CentreSnapshot>;
  onOpen: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  return (
    <Waiting
      query={query}
      label={t('reports.centre.tabs.snapshots')}
      empty={{
        title: t('reports.centre.snapshots.emptyTitle'),
        body: t('reports.centre.snapshots.emptyBody'),
      }}
    >
      {(rows) => (
        <DataTable label={t('reports.centre.tabs.snapshots')} minWidth="48rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('reports.centre.col.report')}</TableHeader>
              <TableHeader>{t('reports.centre.col.label')}</TableHeader>
              <TableHeader>{t('reports.centre.col.taken')}</TableHeader>
              <TableHeader>{t('reports.centre.col.period')}</TableHeader>
              <TableHeader>{t('reports.centre.col.by')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((snapshot) => (
              <TableRow key={snapshot.id}>
                <TableCell>
                  <Button variant="ghost" size="sm" onClick={() => onOpen(snapshot.report_id)}>
                    <span dir="auto">{snapshot.report_name}</span>
                  </Button>
                </TableCell>
                <TableCell label={t('reports.centre.col.label')}>{snapshot.label ?? '—'}</TableCell>
                <TableCell label={t('reports.centre.col.taken')}>
                  {dayOf(snapshot.taken_at, locale)}
                </TableCell>
                <TableCell label={t('reports.centre.col.period')}>
                  {dayOf(snapshot.period_from, locale)} – {dayOf(snapshot.period_until, locale)}
                </TableCell>
                <TableCell label={t('reports.centre.col.by')}>
                  {snapshot.taken_by_name ?? '—'}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}
    </Waiting>
  );
}

/** Every link out of the agency: the answer to "what can somebody outside see right now?". */
export function LinksPanel({
  query,
  onOpen,
}: {
  query: Query<CentreShare>;
  onOpen: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  return (
    <Waiting
      query={query}
      label={t('reports.centre.tabs.links')}
      empty={{
        title: t('reports.centre.links.emptyTitle'),
        body: t('reports.centre.links.emptyBody'),
      }}
    >
      {(rows) => (
        <DataTable label={t('reports.centre.tabs.links')} minWidth="64rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('reports.centre.col.report')}</TableHeader>
              <TableHeader>{t('reports.centre.col.recipient')}</TableHeader>
              <TableHeader>{t('reports.centre.col.state')}</TableHeader>
              <TableHeader>{t('reports.centre.col.mode')}</TableHeader>
              <TableHeader align="end">{t('reports.tracking.views')}</TableHeader>
              <TableHeader>{t('reports.tracking.lastViewed')}</TableHeader>
              <TableHeader>{t('reports.tracking.expires')}</TableHeader>
              <TableHeader>{t('reports.centre.col.by')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((share) => (
              <TableRow key={share.id}>
                <TableCell>
                  <Button variant="ghost" size="sm" onClick={() => onOpen(share.report_id)}>
                    <span dir="auto">{share.report_name}</span>
                  </Button>
                  <span className="text-content-tertiary block text-xs" dir="auto">
                    {share.client_name ?? t('reports.view.allClients')}
                  </span>
                </TableCell>
                <TableCell label={t('reports.centre.col.recipient')}>
                  {share.recipient_label ?? '—'}
                </TableCell>
                <TableCell label={t('reports.centre.col.state')}>
                  <Badge tone={share.state === 'active' ? 'success' : 'neutral'}>
                    {t(`reports.centre.state.${share.state}`, { defaultValue: share.state_label })}
                  </Badge>
                </TableCell>
                <TableCell label={t('reports.centre.col.mode')}>
                  {share.is_frozen
                    ? t('reports.centre.mode.frozen')
                    : t('reports.centre.mode.live')}
                </TableCell>
                <TableCell isNumeric align="end" label={t('reports.tracking.views')}>
                  {share.view_count === 0 ? '—' : formatNumber(share.view_count, locale)}
                </TableCell>
                <TableCell label={t('reports.tracking.lastViewed')}>
                  {share.last_viewed_at === null
                    ? t('reports.tracking.neverOpened')
                    : formatRelativeTime(share.last_viewed_at, locale)}
                </TableCell>
                <TableCell label={t('reports.tracking.expires')}>
                  {dayOf(share.expires_at, locale)}
                </TableCell>
                <TableCell label={t('reports.centre.col.by')}>
                  {share.created_by_name ?? '—'}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}
    </Waiting>
  );
}
