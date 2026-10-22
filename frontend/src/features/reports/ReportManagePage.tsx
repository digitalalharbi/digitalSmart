import { useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Breadcrumbs } from '@/shared/ui/breadcrumbs';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { TabList } from '@/shared/ui/tab-list';
import { csvUrl, xlsxUrl } from './api';
import { ExportsPanel } from './ExportsPanel';
import { ReportTracking } from './ReportTracking';
import { ReportView } from './ReportView';
import { SharePanel } from './SharePanel';
import {
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
} from './useReports';

type ManageTab = 'report' | 'settings' | 'sharing' | 'files';

/**
 * One report's own page: what it says, how it is set up, who can read it and what has been made from it.
 *
 * The library lists every report; this is where one of them is run. Nothing here is new behaviour — the figures, the
 * links, the snapshots, the files and the schedules are the ones the reports centre already has — it is the place they
 * are gathered for a single report, reachable by an address that can be sent to a colleague.
 *
 * **Settings are read here and changed in the editor.** Showing what a report is set to (its period, who it is for,
 * which campaigns it is about, which sections it holds) is the part a person needs before sharing it; the editor stays
 * the one place that changes them, so there is one set of rules for what a valid report is.
 */
export function ReportManagePage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { reportId = '' } = useParams();
  const { can } = useSession();
  const canView = can(PERMISSIONS.reportsView);
  const canManage = can(PERMISSIONS.reportsManage);
  const canShare = can(PERMISSIONS.reportsShare);

  const [tab, setTab] = useState<ManageTab>('report');
  const [freshToken, setFreshToken] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const rendered = useRenderedReportQuery(canView ? reportId : null);
  const draft = useReportDraftQuery(canView ? reportId : null);
  const vocabulary = useReportVocabularyQuery(canView);
  const summaries = useReportsQuery(null, canView);
  const snapshots = useSnapshotsQuery(reportId);
  const shares = useSharesQuery(reportId, canShare);

  const snapshot = useSnapshotMutation();
  const share = useShareMutation();
  const revoke = useRevokeShareMutation();
  const remove = useDeleteReportMutation();

  const summary = summaries.data?.find((entry) => entry.id === reportId);

  const error = useMemo(() => {
    for (const mutation of [snapshot, share, revoke, remove]) {
      if (mutation.error instanceof ApiError && mutation.error.message !== '') {
        return mutation.error.message;
      }
    }

    return null;
  }, [snapshot, share, revoke, remove]);

  if (!canView) {
    return (
      <EmptyState
        title={t('reports.denied.title')}
        level={1}
        description={t('reports.denied.body')}
      />
    );
  }

  if (rendered.isError) {
    return (
      <EmptyState
        level={1}
        title={t('reports.manage.missingTitle')}
        description={t('reports.manage.missingBody')}
        action={
          <Link to="/reports" className={buttonVariants({ variant: 'secondary', size: 'sm' })}>
            {t('reports.manage.back')}
          </Link>
        }
      />
    );
  }

  const name = rendered.data?.name ?? summary?.name ?? '';
  const tabs: Array<{ id: ManageTab; label: string }> = [
    { id: 'report', label: t('reports.manage.tabs.report') },
    { id: 'settings', label: t('reports.manage.tabs.settings') },
    ...(canManage || canShare
      ? [{ id: 'sharing' as const, label: t('reports.manage.tabs.sharing') }]
      : []),
    { id: 'files', label: t('reports.manage.tabs.files') },
  ];
  const definition = draft.data;

  return (
    <div className="flex flex-col gap-5">
      <Breadcrumbs
        label={t('reports.manage.trail')}
        items={[{ label: t('reports.title'), to: '/reports' }, { label: name }]}
      />

      <PageHeader
        title={name === '' ? t('reports.manage.loading') : name}
        description={summary?.description ?? undefined}
        actions={
          canManage ? (
            <Link
              to={`/reports?edit=${reportId}`}
              className={buttonVariants({ variant: 'secondary', size: 'sm' })}
            >
              {t('reports.actions.edit')}
            </Link>
          ) : undefined
        }
      />

      {summary === undefined ? null : (
        <div className="bg-surface border-line flex flex-wrap items-start justify-between gap-3 rounded-2xl border p-4">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={summary.audience === 'client_safe' ? 'accent' : 'neutral'}>
              {t(
                summary.audience === 'client_safe'
                  ? 'reports.card.clientSafe'
                  : 'reports.card.internal',
              )}
            </Badge>
            <Badge tone="neutral">{summary.window_label}</Badge>
            {summary.client_name === null ? null : (
              <Badge tone="neutral">{summary.client_name}</Badge>
            )}
          </div>
          <div className="max-w-md min-w-[16rem] flex-1">
            <ReportTracking report={summary} />
          </div>
        </div>
      )}

      {error === null ? null : <Alert tone="danger">{error}</Alert>}

      <TabList
        tabs={tabs}
        active={tab}
        onChange={setTab}
        label={t('reports.manage.tabsLabel')}
        idPrefix="report-manage"
      />

      <div id="report-manage-panel" role="tabpanel" className="flex flex-col gap-4">
        {tab === 'report' ? (
          rendered.data === undefined ? (
            <Skeleton className="h-96 w-full" />
          ) : (
            <ReportView
              report={rendered.data}
              csvHref={csvUrl(reportId)}
              xlsxHref={xlsxUrl(reportId)}
              presentable
            />
          )
        ) : null}

        {tab === 'settings' ? (
          definition === undefined ? (
            <Skeleton className="h-48 w-full" />
          ) : (
            <div className="flex flex-col gap-4">
              <dl className="bg-surface border-line grid gap-4 rounded-2xl border p-5 sm:grid-cols-2">
                <Setting label={t('reports.manage.settings.client')}>
                  {definition.client_name ?? t('reports.builder.everyClient')}
                </Setting>
                <Setting label={t('reports.manage.settings.period')}>
                  {definition.window === 'custom' && definition.custom_from !== null
                    ? `${definition.custom_from} – ${definition.custom_until ?? ''}`
                    : (vocabulary.data?.windows.find((entry) => entry.window === definition.window)
                        ?.label ?? definition.window)}
                </Setting>
                <Setting label={t('reports.manage.settings.comparison')}>
                  {vocabulary.data?.comparisons.find(
                    (entry) => entry.comparison === definition.comparison,
                  )?.label ?? definition.comparison}
                </Setting>
                <Setting label={t('reports.manage.settings.audience')}>
                  {t(
                    definition.audience === 'client_safe'
                      ? 'reports.card.clientSafe'
                      : 'reports.card.internal',
                  )}
                </Setting>
                <Setting label={t('reports.manage.settings.scope')}>
                  {definition.filters.campaign_ids.length === 0
                    ? t('reports.manage.settings.wholeClient')
                    : t('reports.builder.campaignScope', {
                        count: definition.filters.campaign_ids.length,
                      })}
                </Setting>
                {definition.audience === 'client_safe' ||
                definition.internal_note === null ? null : (
                  <Setting label={t('reports.builder.internalNote')}>
                    {definition.internal_note}
                  </Setting>
                )}
              </dl>

              <section
                aria-label={t('reports.manage.settings.sections')}
                className="flex flex-col gap-2"
              >
                <h2 className="text-content text-sm font-semibold">
                  {t('reports.manage.settings.sections')}
                </h2>
                <ol className="flex flex-col gap-2">
                  {definition.sections.map((section, index) => (
                    <li
                      key={`${section.kind}-${String(index)}`}
                      className="bg-surface-sunken text-content rounded-xl px-4 py-2.5 text-sm"
                    >
                      {section.title ??
                        vocabulary.data?.sections.find((entry) => entry.kind === section.kind)
                          ?.label ??
                        section.kind}
                    </li>
                  ))}
                </ol>
              </section>

              {!canManage ? null : (
                <div className="flex flex-wrap gap-2">
                  <Link
                    to={`/reports?edit=${reportId}`}
                    className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                  >
                    {t('reports.actions.edit')}
                  </Link>
                  {confirmDelete ? (
                    <>
                      <Button
                        variant="danger"
                        size="sm"
                        disabled={remove.isPending}
                        onClick={() =>
                          remove.mutate(reportId, { onSuccess: () => void navigate('/reports') })
                        }
                      >
                        {t('reports.manage.confirmDelete')}
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setConfirmDelete(false)}>
                        {t('actions.cancel')}
                      </Button>
                    </>
                  ) : (
                    <Button variant="ghost" size="sm" onClick={() => setConfirmDelete(true)}>
                      {t('reports.actions.delete')}
                    </Button>
                  )}
                </div>
              )}
            </div>
          )
        ) : null}

        {tab === 'sharing' && rendered.data !== undefined ? (
          <SharePanel
            shares={shares.data ?? []}
            snapshots={snapshots.data ?? []}
            maxDays={vocabulary.data?.max_share_days ?? 90}
            canShare={canShare}
            isInternal={summary?.audience !== 'client_safe'}
            freshToken={freshToken}
            context={{
              client: rendered.data.client_name,
              title: rendered.data.name,
              period: `${rendered.data.period.from} – ${rendered.data.period.until}`,
            }}
            isWorking={snapshot.isPending || share.isPending || revoke.isPending}
            error={error}
            onShare={(input) => {
              share.mutate(
                {
                  reportId,
                  days: input.days,
                  snapshotId: input.snapshotId,
                  ...(input.recipientLabel === '' ? {} : { recipientLabel: input.recipientLabel }),
                },
                { onSuccess: (created) => setFreshToken(created.token) },
              );
            }}
            onRevoke={(shareId, reason) => revoke.mutate({ shareId, reason })}
            onTakeSnapshot={(label) =>
              snapshot.mutate({ reportId, ...(label === '' ? {} : { label }) })
            }
          />
        ) : null}

        {tab === 'files' ? <ExportsPanel reportId={reportId} /> : null}
      </div>
    </div>
  );
}

function Setting({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-content-tertiary ds-overline">{label}</dt>
      <dd className="text-content text-sm [overflow-wrap:anywhere]">{children}</dd>
    </div>
  );
}
