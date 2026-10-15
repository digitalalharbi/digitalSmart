import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import { formatNumber, formatPercent } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';

type Operations = {
  summary: {
    connections: {
      healthy: number;
      needs_reconnect: number;
      sync_issue: number;
      awaiting_access: number;
    };
    accounts_with_sync_trouble: number;
    stale_accounts: number;
    accounts_syncing: number;
    last_sync_at: string | null;
    next_sync_at: string | null;
  };
  advanced: null | {
    queue_depth: Record<string, number>;
    providers: Array<{
      provider: string;
      runs: number;
      failed: number;
      partial: number;
      error_rate: number | null;
    }>;
    failures: Array<{
      account_name: string;
      provider: string;
      kind: string;
      code: string | null;
      message: string | null;
      at: string;
    }>;
    stale: Array<{ account_id: string; provider: string; last_success_at: string | null }>;
    window_hours: number;
  };
};

/**
 * Is the integration layer healthy — in plain counts for anybody, and in full for those who can act on it.
 *
 * The first block is the whole of what most people need: how many connections are fine, how many need
 * somebody, which accounts have gone quiet, when it last ran and when it next will. Queue depth, error
 * rates and the failing runs with their codes are *advanced* and the server only sends them to somebody who
 * may act — so this panel cannot show them by accident.
 */
export function OperationsPanel() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { data, isPending, isError } = useQuery({
    queryKey: queryKeys.integrations.operations(),
    queryFn: async (): Promise<Operations> => {
      const { data: body } = await api.get<{ data: Operations }>('/integrations/operations');

      return body.data;
    },
    staleTime: 15_000,
    refetchInterval: 60_000,
  });

  if (isError) {
    return <Alert tone="danger">{t('operations.loadFailed')}</Alert>;
  }

  if (isPending || data === undefined) {
    return <Skeleton className="h-40 w-full" />;
  }

  const { summary, advanced } = data;
  const tile = (label: string, value: number, bad = false) => (
    <div className="bg-surface border-line-subtle rounded-2xl border p-4 shadow-xs">
      <p className="ds-overline text-content-tertiary">{label}</p>
      <p className={`ds-figure text-2xl ${bad && value > 0 ? 'text-danger' : 'text-content'}`}>
        {formatNumber(value, locale)}
      </p>
    </div>
  );

  return (
    <div className="flex flex-col gap-6">
      <section
        aria-label={t('operations.summary')}
        className="grid grid-cols-2 gap-3 lg:grid-cols-5"
      >
        {tile(t('operations.healthy'), summary.connections.healthy)}
        {tile(t('operations.needsReconnect'), summary.connections.needs_reconnect, true)}
        {tile(t('operations.syncTrouble'), summary.accounts_with_sync_trouble, true)}
        {tile(t('operations.stale'), summary.stale_accounts, true)}
        {tile(t('operations.syncing'), summary.accounts_syncing)}
      </section>

      <p className="text-content-secondary text-sm">
        {summary.last_sync_at === null
          ? t('operations.neverSynced')
          : t('operations.lastSync', { when: formatRelativeTime(summary.last_sync_at, locale) })}
        {' · '}
        {summary.next_sync_at === null
          ? t('operations.nothingScheduled')
          : t('operations.nextSync', { when: formatRelativeTime(summary.next_sync_at, locale) })}
      </p>

      {advanced === null ? (
        <p className="text-content-tertiary text-sm">{t('operations.advancedHidden')}</p>
      ) : (
        <section aria-label={t('operations.advanced')} className="flex flex-col gap-4">
          <h2 className="text-content text-lg font-semibold">{t('operations.advanced')}</h2>

          <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            {Object.entries(advanced.queue_depth).map(([queue, depth]) => (
              <li key={queue} className="bg-surface border-line-subtle rounded-xl border p-3">
                <p className="text-content-tertiary font-mono text-xs">{queue}</p>
                <p className="ds-numeric text-lg">{formatNumber(depth, locale)}</p>
              </li>
            ))}
          </ul>

          <DataTable label={t('operations.providers')} minWidth="36rem">
            <TableHead>
              <TableRow>
                <TableHeader>{t('operations.col.provider')}</TableHeader>
                <TableHeader align="end">{t('operations.col.runs')}</TableHeader>
                <TableHeader align="end">{t('operations.col.failed')}</TableHeader>
                <TableHeader align="end">{t('operations.col.errorRate')}</TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {advanced.providers.map((p) => (
                <TableRow key={p.provider}>
                  <TableCell>{p.provider}</TableCell>
                  <TableCell isNumeric align="end" label={t('operations.col.runs')}>
                    {p.runs}
                  </TableCell>
                  <TableCell isNumeric align="end" label={t('operations.col.failed')}>
                    {p.failed}
                  </TableCell>
                  <TableCell isNumeric align="end" label={t('operations.col.errorRate')}>
                    {p.error_rate === null ? '—' : formatPercent(p.error_rate, locale, 1)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </DataTable>

          {advanced.failures.length === 0 ? (
            <p className="text-content-tertiary text-sm">
              {t('operations.noFailures', { hours: advanced.window_hours })}
            </p>
          ) : (
            <DataTable label={t('operations.failures')} minWidth="48rem">
              <TableHead>
                <TableRow>
                  <TableHeader>{t('operations.col.account')}</TableHeader>
                  <TableHeader>{t('operations.col.kind')}</TableHeader>
                  <TableHeader>{t('operations.col.code')}</TableHeader>
                  <TableHeader>{t('operations.col.message')}</TableHeader>
                </TableRow>
              </TableHead>
              <TableBody>
                {advanced.failures.map((f, i) => (
                  <TableRow key={`${f.at}-${String(i)}`}>
                    <TableCell>{f.account_name || f.provider}</TableCell>
                    <TableCell label={t('operations.col.kind')}>{f.kind}</TableCell>
                    <TableCell label={t('operations.col.code')}>{f.code ?? '—'}</TableCell>
                    <TableCell label={t('operations.col.message')}>{f.message ?? '—'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </DataTable>
          )}
        </section>
      )}
    </div>
  );
}
