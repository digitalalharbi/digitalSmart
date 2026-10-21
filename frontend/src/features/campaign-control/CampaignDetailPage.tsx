import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import {
  HeroTrend,
  PacingStrip,
  analyticsAsReport,
  useAnalytics,
  useMetricCatalogue,
} from '@/features/analytics';
import { ContextInsight } from '@/features/assistant';
import { ReportHighlights } from '@/features/reports';
import { BackgroundWork } from '@/features/background-work';
import { api } from '@/shared/api/client';
import { formatCompact } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { HealthPill } from '@/shared/ui/health';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { KpiTile } from '@/shared/ui/kpi-tile';
import { TrendChart } from '@/shared/ui/viz';
import { BudgetBar } from './CampaignParts';
import { Hierarchy } from './Hierarchy';
import { QuickActions } from './QuickActions';
import { objectiveName, resultName } from './labels';
import { money, resultText } from './text';
import { reasonText } from '@/shared/lib/reason';
import { HEALTH_TO_STATE, type CampaignControlPage, type CampaignRow } from './types';

/** What the campaign's own analytics asks for: the figures the hero chart plots and the funnel reads. */
const ANALYTICS_METRICS = [
  'spend',
  'revenue',
  'roas',
  'conversions',
  'cpa',
  'impressions',
  'clicks',
  'ctr',
  'cpc',
  'cpm',
];

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

  /*
   * The same analytics endpoint, narrowed to this campaign, over the period every analytical page shares. The hero
   * chart, the funnel and the insights below are read from it by the same code the client's live report uses, so the
   * campaign's page and its client's report cannot tell two stories about the same week.
   */
  const period = useUiStore((state) => state.period);
  const catalogue = useMetricCatalogue();
  const [heroMetric, setHeroMetric] = useState('spend');
  const analytics = useAnalytics(
    {
      preset: ({ 7: '7d', 30: '30d', 90: '90d' } as const)[period.days],
      compare: period.compare,
      metrics: ANALYTICS_METRICS,
      order_by: 'spend',
      limit: 10,
      campaign_ids: [campaignId],
    },
    campaignId !== '',
  );
  const asReport = useMemo(
    () =>
      analytics.data === undefined || analytics.data.is_empty
        ? null
        : analyticsAsReport(analytics.data, undefined, undefined),
    [analytics.data],
  );
  const metricLabels = useMemo(
    () => new Map([...catalogue.definitions].map(([key, def]) => [key, def.label])),
    [catalogue.definitions],
  );

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

  /* One label per day, ending on the day it was last synced, since the series carries no dates of its own. */
  const trendValues = control.data?.trend ?? [];
  const syncedAt = row?.last_synced_at ?? null;
  const dayLabels = trendValues.map((_, index) => {
    if (syncedAt === null) {
      return String(index + 1);
    }

    const day = new Date(syncedAt);
    day.setUTCDate(day.getUTCDate() - (trendValues.length - 1 - index));

    return day.toLocaleDateString(locale, { month: 'short', day: 'numeric', timeZone: 'UTC' });
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
              <span className="text-content-secondary text-sm">
                {reasonText(
                  t,
                  row.health_reason_code === undefined
                    ? null
                    : { code: row.health_reason_code, params: row.health_reason_params ?? {} },
                  row.health_reason,
                )}
              </span>
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
              className="grid grid-cols-2 gap-4 lg:grid-cols-3 2xl:grid-cols-5"
            >
              <KpiTile
                highlight
                label={t('campaignControl.col.spend')}
                value={money(row.spend_minor, row.currency, locale)}
              />
              <KpiTile label={resultName(t, row)} value={resultText(row, locale)} />
              <KpiTile
                label={t('campaignControl.col.costPerResult')}
                value={money(row.cost_per_result_minor, row.currency, locale)}
              />
              {row.revenue_minor === null ? null : (
                <KpiTile
                  label={t('campaignDetail.revenue')}
                  value={money(row.revenue_minor, row.currency, locale)}
                />
              )}
              {row.roas === null ? null : (
                <KpiTile label="ROAS" value={`${row.roas.toFixed(2)}×`} />
              )}
            </section>

            {analytics.data === undefined || analytics.data.is_empty ? null : (
              <Card>
                <CardHeader>
                  <CardTitle>{t('campaignDetail.performance')}</CardTitle>
                </CardHeader>
                <CardContent>
                  <HeroTrend
                    data={analytics.data}
                    metric={heroMetric}
                    onMetric={setHeroMetric}
                    label={t('campaignDetail.trendLabel', { name: row.name })}
                    labels={metricLabels}
                  />
                </CardContent>
              </Card>
            )}

            {/* The funnel and what stands out, from the report system's own reader over this campaign's figures. */}
            {asReport === null ? null : <ReportHighlights report={asReport} />}

            {analytics.data !== undefined && !analytics.data.is_empty ? null : (
              <Card>
                <CardHeader>
                  <CardTitle>{t('campaignDetail.trend')}</CardTitle>
                </CardHeader>
                <CardContent>
                  <TrendChart
                    labels={dayLabels}
                    series={[
                      {
                        key: 'spend',
                        label: t('campaignControl.col.spend'),
                        values: (control.data?.trend ?? []).map((v) =>
                          v === null ? null : v / 100,
                        ),
                        colour: 'var(--ds-accent)',
                      },
                    ]}
                    format={(v) => formatCompact(v, locale)}
                    label={t('campaignDetail.trendLabel', { name: row.name })}
                    height={220}
                  />
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader>
                <CardTitle>{t('hierarchy.title')}</CardTitle>
              </CardHeader>
              <CardContent>
                <Hierarchy campaignId={row.id} defaultOpen />
              </CardContent>
            </Card>

            <div className="grid gap-4">
              <Card>
                <CardHeader>
                  <CardTitle>{t('campaignDetail.budget')}</CardTitle>
                </CardHeader>
                <CardContent>
                  <BudgetBar row={row} />
                </CardContent>
              </Card>
              {/* Plan, pace and forecast for this campaign's own budgets: the Budget Control Center's bars. */}
              <PacingStrip clientId={row.client_id} projectId={row.project_id} locale={locale} />
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
                <dd className="text-content">{objectiveName(t, row)}</dd>
              </dl>
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
                  {objectiveName(t, row)} · {row.external_account.name}
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
            <ContextInsight clientId={row.client_id} clientName={row.client_name} />
            <BackgroundWork />
          </aside>
        </div>
      )}
    </div>
  );
}
