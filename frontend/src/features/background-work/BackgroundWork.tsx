import { useQuery } from '@tanstack/react-query';
import { Loader2, CircleCheck, CircleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';

type ExportRow = {
  id: string;
  status: 'queued' | 'running' | 'ready' | 'failed';
  filename: string | null;
  requested_at: string;
};

type Sync = { accounts_syncing: number; last_sync_at: string | null };

/**
 * What the product is doing in the background, in business words.
 *
 * Exports being prepared and accounts being refreshed — in progress, finished, failed — with no queue names,
 * job classes or cursors. Those live in Administration → Advanced diagnostics. It refreshes while anything is
 * running and goes quiet when nothing is, and it shows nothing at all to a reader who has neither an export
 * of their own nor the right to see sync state, rather than an empty card that implies there is something to see.
 */
export function BackgroundWork() {
  const { t, i18n } = useTranslation();

  const exports = useQuery({
    queryKey: ['background-work', 'exports'] as const,
    queryFn: async (): Promise<ExportRow[]> => {
      const { data } = await api.get<{ data: ExportRow[] }>('/exports');

      return Array.isArray(data.data) ? data.data : [];
    },
    retry: false,
    staleTime: 15_000,
    refetchInterval: (query) =>
      (query.state.data ?? []).some((row) => row.status === 'queued' || row.status === 'running')
        ? 5_000
        : false,
  });

  const sync = useQuery({
    queryKey: ['background-work', 'sync'] as const,
    queryFn: async (): Promise<Sync> => {
      const { data } = await api.get<{ data: { summary: Sync } }>('/integrations/operations');

      return data.data.summary;
    },
    retry: false,
    staleTime: 30_000,
  });

  const recent = (exports.data ?? []).slice(0, 5);
  const running = recent.filter(
    (row) => row.status === 'queued' || row.status === 'running',
  ).length;
  const failed = recent.filter((row) => row.status === 'failed').length;
  const syncing = sync.data?.accounts_syncing ?? 0;

  if (exports.isError && sync.isError) {
    return null;
  }

  if (recent.length === 0 && sync.data === undefined) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('backgroundWork.title')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-2 text-sm">
        {sync.data === undefined ? null : (
          <p className="flex items-center gap-2">
            {syncing > 0 ? (
              <Loader2
                aria-hidden
                className="text-info size-4 animate-spin motion-reduce:animate-none"
              />
            ) : (
              <CircleCheck aria-hidden className="text-success size-4" />
            )}
            <span>
              {syncing > 0
                ? t('backgroundWork.syncing', { count: syncing })
                : sync.data.last_sync_at === null
                  ? t('backgroundWork.neverSynced')
                  : t('backgroundWork.synced', {
                      when: formatRelativeTime(sync.data.last_sync_at, i18n.language),
                    })}
            </span>
          </p>
        )}
        {recent.length === 0 ? null : (
          <>
            {running > 0 ? (
              <p className="flex items-center gap-2">
                <Loader2
                  aria-hidden
                  className="text-info size-4 animate-spin motion-reduce:animate-none"
                />
                {t('backgroundWork.exportsRunning', { count: running })}
              </p>
            ) : null}
            {failed > 0 ? (
              <p className="text-danger flex items-center gap-2">
                <CircleAlert aria-hidden className="size-4" />
                {t('backgroundWork.exportsFailed', { count: failed })}
              </p>
            ) : null}
            {running === 0 && failed === 0 ? (
              <p className="flex items-center gap-2">
                <CircleCheck aria-hidden className="text-success size-4" />
                {t('backgroundWork.exportsReady', {
                  count: recent.filter((row) => row.status === 'ready').length,
                })}
              </p>
            ) : null}
          </>
        )}
      </CardContent>
    </Card>
  );
}
