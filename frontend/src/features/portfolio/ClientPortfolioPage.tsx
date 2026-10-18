import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { ContextInsight } from '@/features/assistant';
import { BackgroundWork } from '@/features/background-work';
import { api } from '@/shared/api/client';
import { Button } from '@/shared/ui/button';
import { ActivityFeed } from '@/shared/ui/activity-feed';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { Badge } from '@/shared/ui/badge';
import {
  Count,
  HealthSection,
  KpiGrid,
  MoneyLines,
  PlatformMix,
  RoasLines,
} from './PortfolioParts';
import { usePortfolio } from './usePortfolio';

/** Where each part of a client's work lives. Each is the one implementation of that module, opened narrowed. */
const TABS: Array<{ key: string; to: string; query?: string }> = [
  { key: 'advertising', to: '/campaigns' },
  { key: 'leads', to: '/leads' },
  { key: 'budget', to: '/budget-control' },
  { key: 'creative', to: '/creative-hub' },
  { key: 'reports', to: '/reports' },
];

/**
 * One client: every one of its projects, aggregated.
 *
 * The same read as the agency portfolio, narrowed — so a client's row there and its figures here are the same
 * numbers. Project cards are the drill-down: each is a page that already exists.
 */
export function ClientPortfolioPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { clientId = '' } = useParams();
  const { data, isPending, isError } = usePortfolio(clientId);
  const client = data?.clients[0];
  const navigate = useNavigate();
  const { context, setContext } = useUiStore();
  /* Executive is the answer in four blocks; operational adds the projects and the work waiting on them. */
  const [mode, setMode] = useState<'executive' | 'operational'>('executive');

  const waiting = useQuery({
    queryKey: ['portfolio', 'client-attention', clientId] as const,
    queryFn: async (): Promise<
      Array<{
        id: string;
        message: string;
        severity: string;
        client_id: string | null;
        action: { path: string };
      }>
    > => {
      const { data: body } = await api.get<{
        data: Array<{
          id: string;
          message: string;
          severity: string;
          client_id: string | null;
          action: { path: string };
        }>;
      }>('/attention', { params: { limit: 20, client_id: clientId } });

      return body.data;
    },
    retry: false,
    staleTime: 30_000,
  });

  /* Opening a module from here sets the client as the working context, so the module opens already narrowed. */
  const open = (to: string, query?: string) => {
    if (client !== undefined) {
      setContext({ ...context, client: { id: client.id, label: client.name }, project: null });
    }

    void navigate(query === undefined ? to : `${to}?${query}`);
  };

  /* What changed, for this client — asked of the server for this client, not filtered from the agency's newest. */
  const changes = useQuery({
    queryKey: ['portfolio', 'client-activity', clientId] as const,
    queryFn: async (): Promise<
      Array<{
        id: string;
        sentence: string;
        actor: string | null;
        project_id: string | null;
        at: string;
      }>
    > => {
      const { data: body } = await api.get<{
        data: Array<{
          id: string;
          sentence: string;
          actor: string | null;
          project_id: string | null;
          at: string;
        }>;
      }>('/activity', { params: { limit: 20, client_id: clientId } });

      return body.data;
    },
    retry: false,
    staleTime: 30_000,
    enabled: mode === 'operational',
  });

  return (
    <div className="ds-page">
      <PageHeader
        title={client?.name ?? t('portfolio.client')}
        description={t('portfolio.clientSubtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div
              className="bg-surface-sunken flex rounded-lg p-0.5"
              role="group"
              aria-label={t('portfolio.mode.label')}
            >
              {(['executive', 'operational'] as const).map((key) => (
                <Button
                  key={key}
                  size="sm"
                  variant={mode === key ? 'secondary' : 'ghost'}
                  aria-pressed={mode === key}
                  onClick={() => setMode(key)}
                >
                  {t(`portfolio.mode.${key}`)}
                </Button>
              ))}
            </div>
            <Link to="/clients" className={buttonVariants({ variant: 'ghost' })}>
              {t('portfolio.allClients')}
            </Link>
          </div>
        }
      />

      {isError ? <Alert tone="danger">{t('portfolio.loadFailed')}</Alert> : null}

      {isPending || data === undefined ? (
        <Skeleton className="h-64 w-full" />
      ) : client === undefined ? (
        <EmptyState title={t('portfolio.noClient')} description={t('portfolio.noClientBody')} />
      ) : (
        <>
          <nav
            aria-label={t('portfolio.tabs.label')}
            className="border-line-subtle -mb-2 flex gap-1 overflow-x-auto border-b"
          >
            {TABS.map((tab) => (
              <button
                key={tab.key}
                type="button"
                onClick={() => open(tab.to, tab.query)}
                className="text-content-secondary hover:text-content focus-visible:ring-accent/40 shrink-0 rounded-t-md px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
              >
                {t(`portfolio.tabs.${tab.key}`)}
              </button>
            ))}
          </nav>

          <div
            className={
              mode === 'operational'
                ? 'grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]'
                : 'grid gap-4'
            }
          >
            <div className="flex min-w-0 flex-col gap-4">
              <KpiGrid data={data.kpis} locale={locale} />

              <ContextInsight clientId={clientId} clientName={client.name} />

              <Card>
                <CardHeader>
                  <CardTitle>{t('portfolio.health')}</CardTitle>
                </CardHeader>
                <CardContent>
                  <HealthSection health={data.health} />
                </CardContent>
              </Card>

              {mode === 'operational' ? (
                <section aria-label={t('portfolio.projects')} className="flex flex-col gap-3">
                  <h2 className="text-content text-lg font-semibold">{t('portfolio.projects')}</h2>
                  {data.projects.length === 0 ? (
                    <EmptyState
                      title={t('portfolio.noProjects')}
                      description={t('portfolio.noProjectsBody')}
                    />
                  ) : (
                    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                      {data.projects.map((project) => (
                        <li key={project.id}>
                          <article
                            aria-label={project.name}
                            className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4 shadow-xs"
                          >
                            <div className="flex items-start justify-between gap-2">
                              <Link
                                to={`/projects/${project.id}`}
                                className="text-content font-medium hover:underline"
                              >
                                {project.name}
                              </Link>
                              <Badge tone="neutral">
                                {t(`portfolio.status.${project.status}`, project.status)}
                              </Badge>
                            </div>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                              <div>
                                <dt className="text-content-tertiary text-xs">
                                  {t('portfolio.kpi.spend')}
                                </dt>
                                <dd>
                                  <MoneyLines values={project.spend} locale={locale} />
                                </dd>
                              </div>
                              <div>
                                <dt className="text-content-tertiary text-xs">ROAS</dt>
                                <dd>
                                  <RoasLines values={project.roas} />
                                </dd>
                              </div>
                              <div>
                                <dt className="text-content-tertiary text-xs">
                                  {t('portfolio.kpi.campaigns')}
                                </dt>
                                <dd className="ds-numeric">
                                  {project.active_campaigns} / {project.campaigns}
                                </dd>
                              </div>
                              <div>
                                <dt className="text-content-tertiary text-xs">
                                  {t('portfolio.openWork')}
                                </dt>
                                <dd className="ds-numeric">
                                  <Count value={project.open_work} locale={locale} />
                                </dd>
                              </div>
                            </dl>
                            <HealthSection health={project.campaign_health} />
                          </article>
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              ) : null}

              <Card>
                <CardHeader>
                  <CardTitle>{t('portfolio.platformMix')}</CardTitle>
                </CardHeader>
                <CardContent>
                  <PlatformMix mix={data.platform_mix} locale={locale} />
                </CardContent>
              </Card>
            </div>

            {mode === 'operational' ? (
              <aside aria-label={t('portfolio.context')} className="flex min-w-0 flex-col gap-4">
                <Card>
                  <CardHeader>
                    <CardTitle>{t('workspace.nextActions')}</CardTitle>
                  </CardHeader>
                  <CardContent>
                    {waiting.data === undefined ? (
                      <p className="text-content-tertiary text-sm">{t('workspace.unavailable')}</p>
                    ) : waiting.data.length === 0 ? (
                      <p className="text-content-tertiary text-sm">{t('workspace.nothingNext')}</p>
                    ) : (
                      <ul className="flex flex-col gap-2">
                        {waiting.data.slice(0, 6).map((item) => (
                          <li key={item.id} className="flex flex-col gap-0.5 text-sm">
                            <span dir="auto">{item.message}</span>
                            <Link
                              to={item.action.path}
                              className="text-content-brand text-xs font-medium hover:underline"
                            >
                              {t('workspace.act')}
                            </Link>
                          </li>
                        ))}
                      </ul>
                    )}
                  </CardContent>
                </Card>
                <Card>
                  <CardHeader>
                    <CardTitle>{t('workspace.changes')}</CardTitle>
                  </CardHeader>
                  <CardContent>
                    {changes.isError ? (
                      <p className="text-content-tertiary text-sm">{t('workspace.unavailable')}</p>
                    ) : (
                      <ActivityFeed
                        isPending={changes.isPending}
                        entries={(changes.data ?? []).slice(0, 8).map((row) => ({
                          id: row.id,
                          actor: row.actor,
                          summary: row.sentence,
                          at: row.at,
                        }))}
                      />
                    )}
                  </CardContent>
                </Card>
                <BackgroundWork />
              </aside>
            ) : null}
          </div>
        </>
      )}
    </div>
  );
}
