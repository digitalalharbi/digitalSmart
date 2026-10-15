import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
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

  /* Opening a module from here sets the client as the working context, so the module opens already narrowed. */
  const open = (to: string, query?: string) => {
    if (client !== undefined) {
      setContext({ ...context, client: { id: client.id, label: client.name }, project: null });
    }

    void navigate(query === undefined ? to : `${to}?${query}`);
  };

  return (
    <div className="ds-page">
      <PageHeader
        title={client?.name ?? t('portfolio.client')}
        description={t('portfolio.clientSubtitle')}
        actions={
          <Link to="/clients" className={buttonVariants({ variant: 'ghost' })}>
            {t('portfolio.allClients')}
          </Link>
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

          <KpiGrid data={data.kpis} locale={locale} />

          <Card>
            <CardHeader>
              <CardTitle>{t('portfolio.health')}</CardTitle>
            </CardHeader>
            <CardContent>
              <HealthSection health={data.health} />
            </CardContent>
          </Card>

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

          <Card>
            <CardHeader>
              <CardTitle>{t('portfolio.platformMix')}</CardTitle>
            </CardHeader>
            <CardContent>
              <PlatformMix mix={data.platform_mix} locale={locale} />
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
