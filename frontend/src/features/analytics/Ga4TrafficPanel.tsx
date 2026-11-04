import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { formatNumber, formatPercent } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { FilterSelect } from '@/shared/ui/filter-select';
import { KpiTile } from '@/shared/ui/kpi-tile';
import { Skeleton } from '@/shared/ui/skeleton';
import { SourceNotice } from '@/shared/ui/source-badge';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import { lastDays, useGa4Traffic } from './ga4Traffic';
import type { Ga4Figures } from './ga4Traffic';
import type { MetricSourceValue } from '@/shared/metrics';

/**
 * GA4 website traffic: where sessions came from and how engaged they were.
 *
 * Every figure is the property's own, stored by the sync. A missing figure is "Not reported", never 0; there is no users
 * figure at all, because users are not additive across days or channels and the panel says so rather than summing them.
 * Sessions here are not ad clicks and are never added to them.
 */
export function Ga4TrafficPanel({ days, clientId }: { days: number; clientId: string | null }) {
  const { t, i18n } = useTranslation();
  const [group, setGroup] = useState<'channel' | 'source_medium'>('channel');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState<10 | 25 | 50>(10);

  const period = useMemo(() => lastDays(days), [days]);
  const query = useGa4Traffic({ ...period, group, page, perPage, clientId });

  const count = (value: number | null): string =>
    value === null ? t('analytics.ga4.notReported') : formatNumber(value, i18n.language);
  const rate = (value: number | null): string =>
    value === null ? t('analytics.ga4.notReported') : formatPercent(value, i18n.language);
  const seconds = (value: number | null): string =>
    value === null
      ? t('analytics.ga4.notReported')
      : t('analytics.ga4.secondsValue', { value: formatNumber(value, i18n.language, 1) });
  const change = (value: number | null): string | undefined =>
    value === null
      ? undefined
      : t('analytics.ga4.vsPrevious', {
          change: `${value > 0 ? '+' : ''}${formatPercent(value, i18n.language)}`,
        });

  if (query.isPending) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (query.isError) {
    const message =
      query.error instanceof ApiError && query.error.message !== '' ? query.error.message : null;

    return (
      <Alert tone="danger" className="flex flex-col items-start gap-3">
        <span>{message ?? t('analytics.ga4.failed')}</span>
        <Button variant="secondary" size="sm" onClick={() => void query.refetch()}>
          {t('actions.retry')}
        </Button>
      </Alert>
    );
  }

  const { data, meta } = query.data;
  const totals: Ga4Figures = data.totals;
  const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('analytics.ga4.title')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {data.quality.source === null ? null : (
          <SourceNotice source={data.quality.source as MetricSourceValue} />
        )}

        {data.quality.no_data ? (
          <EmptyState
            title={t('analytics.ga4.emptyTitle')}
            description={t('analytics.ga4.emptyBody')}
          />
        ) : (
          <>
            {data.quality.is_complete ? null : (
              <p className="text-content-secondary text-sm">
                {t('analytics.ga4.coverage', {
                  present: data.quality.days_present,
                  total: data.quality.days_in_period,
                })}
              </p>
            )}

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
              <KpiTile
                label={t('analytics.ga4.sessions')}
                value={count(totals.sessions)}
                sub={change(data.change.sessions)}
                highlight
              />
              <KpiTile
                label={t('analytics.ga4.engagedSessions')}
                value={count(totals.engaged_sessions)}
                sub={change(data.change.engaged_sessions)}
              />
              <KpiTile
                label={t('analytics.ga4.engagementRate')}
                value={rate(totals.engagement_rate)}
              />
              <KpiTile
                label={t('analytics.ga4.engagementPerSession')}
                value={seconds(totals.engagement_seconds_per_session)}
              />
              <KpiTile
                label={t('analytics.ga4.pageViews')}
                value={count(totals.page_views)}
                sub={change(data.change.page_views)}
              />
            </div>

            <div className="flex flex-wrap items-end gap-3">
              <FilterSelect
                label={t('analytics.ga4.groupBy')}
                value={group}
                any={null}
                onChange={(value) => {
                  setGroup(value === 'source_medium' ? 'source_medium' : 'channel');
                  setPage(1);
                }}
                options={[
                  { value: 'channel', label: t('analytics.ga4.byChannel') },
                  { value: 'source_medium', label: t('analytics.ga4.bySourceMedium') },
                ]}
              />
              <FilterSelect
                label={t('analytics.ga4.rows')}
                value={String(perPage)}
                any={null}
                onChange={(value) => {
                  setPerPage(value === '50' ? 50 : value === '25' ? 25 : 10);
                  setPage(1);
                }}
                options={[
                  { value: '10', label: '10' },
                  { value: '25', label: '25' },
                  { value: '50', label: '50' },
                ]}
              />
            </div>

            <DataTable label={t('analytics.ga4.tableLabel')} minWidth="40rem">
              <TableHead>
                <TableRow>
                  <TableHeader>
                    {group === 'channel'
                      ? t('analytics.ga4.channel')
                      : t('analytics.ga4.sourceMedium')}
                  </TableHeader>
                  <TableHeader align="end">{t('analytics.ga4.sessions')}</TableHeader>
                  <TableHeader align="end">{t('analytics.ga4.share')}</TableHeader>
                  <TableHeader align="end">{t('analytics.ga4.engagedSessions')}</TableHeader>
                  <TableHeader align="end">{t('analytics.ga4.engagementRate')}</TableHeader>
                  <TableHeader align="end">{t('analytics.ga4.pageViews')}</TableHeader>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.rows.map((row) => (
                  <TableRow key={row.name}>
                    <TableCell
                      label={
                        group === 'channel'
                          ? t('analytics.ga4.channel')
                          : t('analytics.ga4.sourceMedium')
                      }
                    >
                      {row.name}
                    </TableCell>
                    <TableCell align="end" isNumeric label={t('analytics.ga4.sessions')}>
                      {count(row.sessions)}
                    </TableCell>
                    <TableCell align="end" isNumeric label={t('analytics.ga4.share')}>
                      {rate(row.share_of_sessions)}
                    </TableCell>
                    <TableCell align="end" isNumeric label={t('analytics.ga4.engagedSessions')}>
                      {count(row.engaged_sessions)}
                    </TableCell>
                    <TableCell align="end" isNumeric label={t('analytics.ga4.engagementRate')}>
                      {rate(row.engagement_rate)}
                    </TableCell>
                    <TableCell align="end" isNumeric label={t('analytics.ga4.pageViews')}>
                      {count(row.page_views)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </DataTable>

            <div className="flex items-center justify-between gap-3">
              <span className="text-content-secondary text-sm">
                {t('analytics.ga4.page', { page: meta.page, pages })}
              </span>
              <span className="flex gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={meta.page <= 1}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  {t('actions.previous')}
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={meta.page >= pages}
                  onClick={() => setPage((current) => current + 1)}
                >
                  {t('actions.next')}
                </Button>
              </span>
            </div>

            <p className="text-content-tertiary text-xs">
              {t('analytics.ga4.footnote', { zones: data.quality.time_zones.join(', ') })}
            </p>
          </>
        )}
      </CardContent>
    </Card>
  );
}
