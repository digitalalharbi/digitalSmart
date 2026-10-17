import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { AttentionPanel } from '@/features/attention';
import { SpendHero } from '@/features/campaign-control';
import { ClientRanking, KpiGrid, ProjectMatrix, usePortfolio } from '@/features/portfolio';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import { formatMinor } from '@/shared/lib/format';
import { Badge } from '@/shared/ui/badge';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { HealthPill } from '@/shared/ui/health';
import type { HealthState } from '@/shared/ui/health.model';
import { Skeleton } from '@/shared/ui/skeleton';
import { BudgetTotalsCards, ClientBudgetRanking, Stat, StatRow } from './RoleParts';
import { useBudgetOverview, useMyCampaignMeta } from './useRoleData';

type Row = {
  id: string;
  name: string;
  client_name: string | null;
  project_name: string | null;
  health: 'healthy' | 'watch' | 'needs_attention' | 'critical' | 'inactive' | 'insufficient_data';
  health_reason: string;
  spend_minor: number | null;
  currency: string | null;
};

const STATE: Record<Row['health'], HealthState> = {
  healthy: 'healthy',
  watch: 'watch',
  needs_attention: 'attention',
  critical: 'critical',
  inactive: 'inactive',
  insufficient_data: 'unknown',
};

/**
 * A short list of campaigns, from the control center's own endpoint.
 *
 * Not a second implementation of anything: it asks `/campaigns/control` for the worst few, so the verdict on
 * the home screen is by construction the verdict on the row it links to.
 */
function CampaignShortlist({ mine, title }: { mine: boolean; title: string }) {
  const { t, i18n } = useTranslation();
  const params = { mine: mine ? 1 : undefined, limit: 6, sort: 'health' };
  const { data, isPending } = useQuery({
    queryKey: queryKeys.home.campaigns(params),
    queryFn: async (): Promise<Row[]> => {
      const { data: body } = await api.get<{ data: Row[] }>('/campaigns/control', { params });

      return body.data;
    },
    staleTime: 30_000,
  });

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3">
        <CardTitle>{title}</CardTitle>
        <Link
          to={mine ? '/campaigns?mine=1' : '/campaigns'}
          className={buttonVariants({ variant: 'ghost', size: 'sm' })}
        >
          {t('home.openAll')}
        </Link>
      </CardHeader>
      <CardContent>
        {isPending ? (
          <Skeleton className="h-24" />
        ) : (data ?? []).length === 0 ? (
          <p className="text-content-tertiary text-sm">{t('home.noCampaigns')}</p>
        ) : (
          <ul className="flex flex-col">
            {(data ?? []).map((row) => (
              <li
                key={row.id}
                className="border-line-subtle flex flex-col gap-1 border-b py-2.5 last:border-0"
              >
                <div className="flex items-center justify-between gap-3">
                  <span className="text-content truncate text-sm font-medium">{row.name}</span>
                  <HealthPill state={STATE[row.health]} />
                </div>
                <p className="text-content-secondary text-xs">{row.health_reason}</p>
                <p className="text-content-tertiary text-xs">
                  {[row.client_name, row.project_name].filter(Boolean).join(' · ')}
                  {row.spend_minor !== null && row.currency !== null
                    ? ` · ${formatMinor(row.spend_minor, row.currency, i18n.language)}`
                    : ''}
                </p>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function BudgetShortlist() {
  const { t, i18n } = useTranslation();
  /* The same read the totals and the ranking use, so a finance home is one request for budgets, not three. */
  const overview = useBudgetOverview();
  const isPending = overview.isPending;
  const data = (overview.data?.data ?? []).filter((b) => b.risks.length > 0).slice(0, 6);

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3">
        <CardTitle>{t('home.budgetRisk')}</CardTitle>
        <Link to="/budget-control" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
          {t('home.openAll')}
        </Link>
      </CardHeader>
      <CardContent>
        {isPending ? (
          <Skeleton className="h-24" />
        ) : data.length === 0 ? (
          <p className="text-content-tertiary text-sm">{t('home.noBudgetRisk')}</p>
        ) : (
          <ul className="flex flex-col">
            {data.map((row) => (
              <li
                key={row.id}
                className="border-line-subtle flex flex-col gap-1 border-b py-2.5 last:border-0"
              >
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="text-content min-w-0 truncate font-medium" dir="auto">
                    {[row.client.name, row.name].join(' · ')}
                  </span>
                  <span className="ds-numeric text-content-secondary">
                    {row.spent_minor === null
                      ? '—'
                      : formatMinor(row.spent_minor, row.currency, i18n.language)}{' '}
                    / {formatMinor(row.approved_minor, row.currency, i18n.language)}
                  </span>
                </div>
                <span className="flex flex-wrap gap-1">
                  {row.risks.map((r) => (
                    <Badge key={r.risk} tone={r.risk === 'overspend' ? 'danger' : 'warning'}>
                      {r.label}
                    </Badge>
                  ))}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

type CreativeColumns = { columns: Array<{ state: string; label: string; requests: unknown[] }> };

function CreativePipeline() {
  const { t } = useTranslation();
  const { data, isPending } = useQuery({
    queryKey: queryKeys.home.creative(),
    queryFn: async (): Promise<CreativeColumns> => {
      const { data: body } = await api.get<{ data: CreativeColumns }>('/creative/board');

      return body.data;
    },
    staleTime: 30_000,
  });

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3">
        <CardTitle>{t('home.creativePipeline')}</CardTitle>
        <Link to="/creative-hub" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
          {t('home.openAll')}
        </Link>
      </CardHeader>
      <CardContent>
        {isPending || data === undefined ? (
          <Skeleton className="h-24" />
        ) : (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {data.columns.map((column) => (
              <li key={column.state} className="bg-surface-sunken rounded-xl p-3">
                <p className="text-content-tertiary text-xs">{column.label}</p>
                <p className="ds-figure text-xl">{column.requests.length}</p>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

/** The sections a profile is made of. Each profile is its own composition, not the same page with parts hidden. */
export function HomeSections({ profile }: { profile: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const portfolio = usePortfolio();
  const mine = useMyCampaignMeta();

  switch (profile) {
    case 'executive':
      return (
        <div className="flex flex-col gap-4">
          {portfolio.data === undefined ? (
            <Skeleton className="h-28 w-full" />
          ) : (
            <KpiGrid data={portfolio.data.kpis} locale={locale} />
          )}
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>{t('portfolio.ranking')}</CardTitle>
              </CardHeader>
              <CardContent>
                {portfolio.data === undefined ? (
                  <Skeleton className="h-32" />
                ) : (
                  <ClientRanking clients={portfolio.data.clients} locale={locale} />
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>{t('portfolio.projectMatrix')}</CardTitle>
              </CardHeader>
              <CardContent>
                {portfolio.data === undefined ? (
                  <Skeleton className="h-32" />
                ) : (
                  <ProjectMatrix projects={portfolio.data.projects} />
                )}
              </CardContent>
            </Card>
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <AttentionPanel limit={5} />
            <BudgetShortlist />
          </div>
        </div>
      );
    case 'finance':
      return (
        <div className="flex flex-col gap-4">
          <BudgetTotalsCards locale={locale} />
          <div className="grid gap-4 lg:grid-cols-2">
            <ClientBudgetRanking />
            <BudgetShortlist />
          </div>
          <AttentionPanel limit={5} />
        </div>
      );
    case 'media_buyer':
      return (
        <div className="flex flex-col gap-4">
          {mine.data === undefined ? (
            <Skeleton className="h-24 w-full" />
          ) : (
            <>
              <StatRow label={t('home.myCampaigns')}>
                <Stat
                  label={t('campaignControl.tile.active')}
                  value={mine.data.active_count}
                  to="/campaigns?mine=1"
                />
                <Stat
                  label={t('campaignControl.tile.healthy')}
                  value={mine.data.health_counts.healthy}
                />
                <Stat
                  label={t('campaignControl.tile.attention')}
                  value={mine.data.health_counts.needs_attention + mine.data.health_counts.watch}
                  tone="warning"
                />
                <Stat
                  label={t('campaignControl.tile.critical')}
                  value={mine.data.health_counts.critical}
                  tone="danger"
                />
              </StatRow>
              <SpendHero meta={mine.data} locale={locale} />
            </>
          )}
          <div className="grid gap-4 lg:grid-cols-2">
            <CampaignShortlist mine title={t('home.decliningCampaigns')} />
            <AttentionPanel limit={5} />
          </div>
        </div>
      );
    case 'project_manager':
      return (
        <div className="flex flex-col gap-4">
          {portfolio.data === undefined ? (
            <Skeleton className="h-24 w-full" />
          ) : (
            <StatRow label={t('home.myProjects')}>
              <Stat label={t('portfolio.kpi.clients')} value={portfolio.data.kpis.active_clients} />
              <Stat
                label={t('portfolio.kpi.projects')}
                value={portfolio.data.kpis.active_projects}
                to="/projects"
              />
              <Stat
                label={t('portfolio.col.attention')}
                value={portfolio.data.kpis.needs_attention}
                tone="warning"
                to="/campaigns"
              />
              <Stat
                label={t('portfolio.col.budgetRisks')}
                value={portfolio.data.budget_health?.at_risk ?? '—'}
                tone="danger"
                to="/budget-control"
              />
            </StatRow>
          )}
          <Card>
            <CardHeader>
              <CardTitle>{t('portfolio.projectMatrix')}</CardTitle>
            </CardHeader>
            <CardContent>
              {portfolio.data === undefined ? (
                <Skeleton className="h-32" />
              ) : (
                <ProjectMatrix projects={portfolio.data.projects} />
              )}
            </CardContent>
          </Card>
          <div className="grid gap-4 lg:grid-cols-2">
            <AttentionPanel limit={6} />
            <CampaignShortlist mine title={t('home.myCampaigns')} />
          </div>
        </div>
      );
    case 'creative':
      return (
        <div className="grid gap-4 lg:grid-cols-2">
          <CreativePipeline />
          <AttentionPanel limit={5} />
        </div>
      );
    default:
      return <AttentionPanel limit={6} />;
  }
}
