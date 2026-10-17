import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { csvUrl, xlsxUrl } from './api';
import { ExportsPanel } from './ExportsPanel';
import { ReportBuilder } from './ReportBuilder';
import { ReportCover } from './ReportCover';
import { ReportView } from './ReportView';
import { SharePanel } from './SharePanel';
import {
  useCreateReportMutation,
  useDeleteReportMutation,
  useRenderedReportQuery,
  useReportDraftQuery,
  useReportVocabularyQuery,
  useReportsQuery,
  useRevokeShareMutation,
  useShareMutation,
  useSharesQuery,
  useSnapshotMutation,
  useSnapshotsQuery,
  useUpdateReportMutation,
} from './useReports';

/**
 * Reports: the saved ones, the one being read, and the links out.
 *
 * The list deliberately shows **how many active links each report has**. It is the figure somebody checks before
 * saying "nobody outside can see this", and a product that made it hard to find would be a product where that
 * sentence gets said without checking.
 */
export function ReportsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can } = useSession();

  const canView = can(PERMISSIONS.reportsView);
  const canManage = can(PERMISSIONS.reportsManage);
  const canShare = can(PERMISSIONS.reportsShare);

  /* Searched, never listed: a hundred-client agency cannot be a row of buttons, and the 101st is not a typo. */
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [clientTerm, setClientTerm] = useState('');
  const clientId = client?.id ?? null;
  const [selected, setSelected] = useState<string | null>(null);
  const [editing, setEditing] = useState<'new' | 'existing' | null>(null);
  const [freshToken, setFreshToken] = useState<string | null>(null);

  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const vocabulary = useReportVocabularyQuery(canView);
  const reports = useReportsQuery(clientId, canView);
  const rendered = useRenderedReportQuery(editing === null ? selected : null);
  const draft = useReportDraftQuery(editing === 'existing' ? selected : null);
  const snapshots = useSnapshotsQuery(selected);
  const shares = useSharesQuery(selected, canShare);

  const create = useCreateReportMutation();
  const update = useUpdateReportMutation();
  const remove = useDeleteReportMutation();
  const snapshot = useSnapshotMutation();
  const share = useShareMutation();
  const revoke = useRevokeShareMutation();

  const error = useMemo(() => {
    for (const mutation of [create, update, remove, snapshot, share, revoke]) {
      if (mutation.error instanceof ApiError && mutation.error.message !== '') {
        return mutation.error.message;
      }
    }

    return null;
  }, [create, update, remove, snapshot, share, revoke]);

  if (!canView) {
    return <EmptyState title={t('reports.denied.title')} description={t('reports.denied.body')} />;
  }

  const list = reports.data ?? [];
  const current = list.find((entry) => entry.id === selected);

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-4">
        <PageHeader
          title={t('reports.title')}
          description={t('reports.subtitle')}
          primaryAction={
            !canManage ? undefined : (
              <Button
                onClick={() => {
                  setSelected(null);
                  setEditing('new');
                  setFreshToken(null);
                }}
              >
                {t('reports.new')}
              </Button>
            )
          }
        />

        <EntityPicker
          label={t('reports.scope.client')}
          clearLabel={t('actions.clear')}
          value={client}
          onChange={(option) =>
            setClient(option === null ? null : { id: option.id, label: option.label })
          }
          onSearch={setClientTerm}
          options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
          isLoading={clients.isFetching}
        />
      </header>

      {error === null ? null : <Alert tone="danger">{error}</Alert>}
      {reports.isError ? <Alert tone="danger">{t('reports.loadFailed')}</Alert> : null}

      {reports.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : list.length === 0 ? (
        <EmptyState title={t('reports.empty.title')} description={t('reports.empty.body')} />
      ) : (
        <section
          aria-label={t('reports.saved')}
          className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
          {list.map((entry) => (
            <button
              key={entry.id}
              type="button"
              onClick={() => {
                setSelected(entry.id);
                setEditing(null);
                setFreshToken(null);
              }}
              aria-pressed={selected === entry.id}
              className={cn(
                'bg-surface border-line-subtle rounded-2xl border p-4 text-start shadow-xs',
                'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
                'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                selected === entry.id && 'border-accent ring-accent/25 ring-1',
              )}
            >
              <ReportCover
                name={entry.name}
                client={entry.client_name ?? t('reports.view.allClients')}
                window={entry.window_label}
                sections={entry.section_count}
                clientSafe={entry.audience === 'client_safe'}
              />
              <span className="text-content mt-3 block text-sm font-medium" dir="auto">
                {entry.name}
              </span>
              <span className="text-content-tertiary mt-1 block text-xs" dir="auto">
                {entry.client_name ?? t('reports.view.allClients')} · {entry.window_label}
              </span>
              <span className="mt-2 flex flex-wrap items-center gap-1.5">
                <Badge tone={entry.audience === 'client_safe' ? 'accent' : 'neutral'}>
                  {t(
                    entry.audience === 'client_safe'
                      ? 'reports.card.clientSafe'
                      : 'reports.card.internal',
                  )}
                </Badge>
                {entry.snapshot_count > 0 ? (
                  <Badge tone="neutral">
                    {t('reports.card.snapshots', { count: entry.snapshot_count })}
                  </Badge>
                ) : null}
                {/*
                  The figure somebody checks before saying "nobody outside can see this". On the card, not behind
                  a click.
                */}
                {entry.active_share_count > 0 ? (
                  <Badge tone="warning">
                    {t('reports.card.shared', {
                      count: formatNumber(entry.active_share_count, locale),
                    })}
                  </Badge>
                ) : null}
              </span>
            </button>
          ))}
        </section>
      )}

      {editing !== null ? (
        <ReportBuilder
          onSearchClients={setClientTerm}
          isSearching={clients.isFetching}
          /* Keyed, so switching between a new report and an edit cannot carry one's fields into the other. */
          key={editing === 'new' ? 'new' : (selected ?? 'existing')}
          vocabulary={vocabulary.data}
          clients={(clients.data?.data ?? []).map((client) => ({
            id: client.id,
            name: client.name,
          }))}
          draft={editing === 'existing' ? draft.data : undefined}
          isSaving={create.isPending || update.isPending}
          error={error}
          onSubmit={(input) => {
            if (editing === 'existing' && selected !== null) {
              update.mutate(
                { ...input, reportId: selected },
                { onSuccess: () => setEditing(null) },
              );

              return;
            }

            create.mutate(input, {
              onSuccess: (saved) => {
                setSelected(saved.id);
                setEditing(null);
              },
            });
          }}
          onCancel={() => setEditing(null)}
        />
      ) : selected === null ? (
        <EmptyState title={t('reports.pick.title')} description={t('reports.pick.body')} />
      ) : rendered.isPending ? (
        <Skeleton className="h-96 w-full" />
      ) : rendered.data === undefined ? null : (
        <>
          <div className="flex flex-wrap gap-2">
            {!canManage ? null : (
              <>
                <Button variant="secondary" size="sm" onClick={() => setEditing('existing')}>
                  {t('reports.actions.edit')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    remove.mutate(selected, {
                      onSuccess: () => {
                        setSelected(null);
                        setFreshToken(null);
                      },
                    });
                  }}
                >
                  {t('reports.actions.delete')}
                </Button>
              </>
            )}
          </div>

          <ReportView
            report={rendered.data}
            csvHref={csvUrl(selected)}
            xlsxHref={xlsxUrl(selected)}
          />

          <ExportsPanel reportId={selected} />

          {!canManage ? null : (
            <SharePanel
              shares={shares.data ?? []}
              snapshots={snapshots.data ?? []}
              maxDays={vocabulary.data?.max_share_days ?? 90}
              canShare={canShare}
              isInternal={current?.audience !== 'client_safe'}
              freshToken={freshToken}
              isWorking={snapshot.isPending || share.isPending || revoke.isPending}
              error={error}
              onShare={(input) => {
                share.mutate(
                  {
                    reportId: selected,
                    days: input.days,
                    snapshotId: input.snapshotId,
                    ...(input.recipientLabel === ''
                      ? {}
                      : { recipientLabel: input.recipientLabel }),
                  },
                  {
                    /* Held in state, because it is never fetched again. */
                    onSuccess: (created) => setFreshToken(created.token),
                  },
                );
              }}
              onRevoke={(shareId, reason) => revoke.mutate({ shareId, reason })}
              onTakeSnapshot={(label) =>
                snapshot.mutate({
                  reportId: selected,
                  ...(label === '' ? {} : { label }),
                })
              }
            />
          )}
        </>
      )}
    </div>
  );
}
