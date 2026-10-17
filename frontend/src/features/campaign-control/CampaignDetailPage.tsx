import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { BackgroundWork } from '@/features/background-work';
import { api } from '@/shared/api/client';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { HealthPill } from '@/shared/ui/health';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { Sparkline } from '@/shared/ui/sparkline';
import { BudgetBar } from './CampaignParts';
import { QuickActions } from './QuickActions';
import { money, resultText } from './text';
import { HEALTH_TO_STATE, type CampaignControlPage, type CampaignRow } from './types';

type Readiness = {
  overall: { state: string; label: string };
  checks: Array<{ key: string; state: string; label: string; reason: string }>;
};

/**
 * One campaign.
 *
 * Read through the control center's own endpoint, asked for by id — so the verdict here is by construction the
 * verdict on the card that linked to it. Figures appear only where the objective measures them: revenue and ROAS
 * are absent, not zero, for a campaign that was never built to produce either.
 */
export function CampaignDetailPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { campaignId = '' } = useParams();

  const control = useQuery({
    queryKey: ['campaign-detail', campaignId] as const,
    queryFn: async (): Promise<{ row: CampaignRow | null; trend: Array<number | null> }> => {
      const { data } = await api.get<CampaignControlPage>('/campaigns/control', {
        params: { campaign_id: [campaignId], limit: 1 },
      });

      return { row: data.data[0] ?? null, trend: data.meta.spend_trend[0]?.points ?? [] };
    },
  });

  const readiness = useQuery({
    queryKey: ['campaign-readiness', campaignId] as const,
    queryFn: async (): Promise<Readiness> => {
      const { data } = await api.get<{ data: Readiness }>(`/campaigns/${campaignId}/readiness`);

      return data.data;
    },
    retry: false,
  });

  const row = control.data?.row ?? null;

  /* The work already raised about this campaign — the next thing to do is often a task somebody has open. */
  const tasks = useQuery({
    queryKey: ['campaign-detail', campaignId, 'tasks'] as const,
    queryFn: async (): Promise<
      Array<{
        id: string;
        title: string;
        state: string;
        assignee_name: string | null;
        is_overdue: boolean;
      }>
    > => {
      const { data } = await api.get<{
        data: {
          columns: Array<{
            state: string;
            tasks: Array<{
              id: string;
              title: string;
              state: string;
              assignee_name: string | null;
              is_overdue: boolean;
            }>;
          }>;
        };
      }>('/tasks/board', { params: { subject_type: 'campaign', subject_id: campaignId } });

      return data.data.columns
        .filter((column) => column.state !== 'done' && column.state !== 'cancelled')
        .flatMap((column) => column.tasks);
    },
    retry: false,
    staleTime: 30_000,
  });

  return (
    <div className="ds-page">
      <PageHeader
        title={row?.name ?? t('campaignDetail.title')}
        description={
          row === null
            ? undefined
            : [row.client_name, row.project_name, row.external_account.name]
                .filter(Boolean)
                .join(' · ')
        }
        actions={
          <Link to="/campaigns" className={buttonVariants({ variant: 'ghost' })}>
            {t('campaignDetail.back')}
          </Link>
        }
      />

      {control.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : row === null ? (
        <Alert tone="warning">{t('campaignDetail.notFound')}</Alert>
      ) : (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
          <div className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
              <HealthPill state={HEALTH_TO_STATE[row.health]} />
              <span className="text-content-secondary text-sm">{row.health_reason}</span>
              {row.last_synced_at === null ? null : (
                <span className="text-content-tertiary text-xs">
                  {t('campaignDetail.updated', {
                    when: formatRelativeTime(row.last_synced_at, locale),
                  })}
                </span>
              )}
            </div>

            <section
              aria-label={t('campaignDetail.kpis')}
              className="grid grid-cols-2 gap-3 lg:grid-cols-5"
            >
              <Figure
                label={t('campaignControl.col.spend')}
                value={money(row.spend_minor, row.currency, locale)}
              />
              <Figure label={row.result_label.replace('_', ' ')} value={resultText(row, locale)} />
              <Figure
                label={t('campaignControl.col.costPerResult')}
                value={money(row.cost_per_result_minor, row.currency, locale)}
              />
              {row.revenue_minor === null ? null : (
                <Figure
                  label={t('campaignDetail.revenue')}
                  value={money(row.revenue_minor, row.currency, locale)}
                />
              )}
              {row.roas === null ? null : <Figure label="ROAS" value={`${row.roas.toFixed(2)}×`} />}
            </section>

            <Card>
              <CardHeader>
                <CardTitle>{t('campaignDetail.trend')}</CardTitle>
              </CardHeader>
              <CardContent>
                <Sparkline
                  stretch
                  values={control.data?.trend ?? []}
                  label={t('campaignDetail.trendLabel', { name: row.name })}
                  className="h-32 w-full"
                />
              </CardContent>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
              <Card>
                <CardHeader>
                  <CardTitle>{t('campaignDetail.budget')}</CardTitle>
                </CardHeader>
                <CardContent>
                  <BudgetBar row={row} />
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>{t('campaignDetail.readiness')}</CardTitle>
                </CardHeader>
                <CardContent>
                  {readiness.data === undefined ? (
                    <p className="text-content-tertiary text-sm">
                      {t('campaignDetail.noReadiness')}
                    </p>
                  ) : (
                    <ul className="flex flex-col gap-2">
                      {readiness.data.checks.map((check) => (
                        <li key={check.key} className="flex flex-col gap-0.5 text-sm">
                          <span className="text-content font-medium">
                            {t(`campaignDetail.check.${check.key}`)}
                            <span className="text-content-tertiary ms-2 text-xs font-normal">
                              {check.label}
                            </span>
                          </span>
                          <span className="text-content-secondary text-xs">{check.reason}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </CardContent>
              </Card>
            </div>

            <details className="text-sm">
              <summary className="text-content-secondary cursor-pointer font-medium">
                {t('integrations.advanced')}
              </summary>
              <dl className="text-content-secondary mt-2 grid grid-cols-2 gap-2 text-xs">
                <dt>{t('campaignDetail.providerStatus')}</dt>
                <dd className="text-content">{row.provider_status ?? '—'}</dd>
                <dt>{t('campaignDetail.account')}</dt>
                <dd className="text-content">{row.external_account.name}</dd>
                <dt>{t('campaignDetail.objective')}</dt>
                <dd className="text-content">{row.objective_label}</dd>
              </dl>
            </details>
          </div>

          <aside aria-label={t('campaignDetail.context')} className="flex min-w-0 flex-col gap-4">
            <Card>
              <CardHeader>
                <CardTitle>{t('campaignDetail.where')}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2 text-sm">
                {row.client_id === null ? null : (
                  <Link to={`/clients/${row.client_id}`} className="hover:underline" dir="auto">
                    {row.client_name}
                  </Link>
                )}
                {row.project_id === null ? null : (
                  <Link to={`/projects/${row.project_id}`} className="hover:underline" dir="auto">
                    {row.project_name}
                  </Link>
                )}
                <span className="text-content-tertiary text-xs">
                  {row.objective_label} · {row.external_account.name}
                </span>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>{t('campaignDetail.tasks')}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                {tasks.data === undefined ? (
                  <p className="text-content-tertiary text-sm">{t('workspace.unavailable')}</p>
                ) : tasks.data.length === 0 ? (
                  <p className="text-content-tertiary text-sm">{t('campaignDetail.noTasks')}</p>
                ) : (
                  <ul className="flex flex-col gap-1.5">
                    {tasks.data.slice(0, 6).map((task) => (
                      <li key={task.id} className="flex items-center justify-between gap-2 text-sm">
                        <span className="min-w-0 truncate" dir="auto">
                          {task.title}
                        </span>
                        <span
                          className={`shrink-0 text-xs ${task.is_overdue ? 'text-danger' : 'text-content-tertiary'}`}
                        >
                          {task.assignee_name ?? t('attention.unassigned')}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
                <QuickActions row={row} />
              </CardContent>
            </Card>
            <BackgroundWork />
          </aside>
        </div>
      )}
    </div>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-surface border-line-subtle flex flex-col gap-1 rounded-2xl border p-4 shadow-xs">
      <span className="ds-overline text-content-tertiary capitalize">{label}</span>
      <span className="ds-figure text-content text-2xl">{value}</span>
    </div>
  );
}
