import { useEffect } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { formatMinor } from '@/shared/lib/format';
import { BackgroundWork } from '@/features/background-work';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { HealthPill } from '@/shared/ui/health';
import type { HealthState } from '@/shared/ui/health.model';
import { Skeleton } from '@/shared/ui/skeleton';
import { ProjectStatusBadge } from './ProjectStatusBadge';
import {
  useClientReports,
  useProjectActivity,
  useProjectApprovals,
  useProjectAttention,
  useProjectBudgets,
  useProjectCampaigns,
  useProjectCreative,
  useProjectDetail,
  useProjectLeads,
  useProjectTasks,
  useProjectTeam,
  useReadiness,
} from './workspace/data';
import {
  Activity,
  ApprovalsList,
  BudgetList,
  CampaignsList,
  CreativeList,
  LeadsSummary,
  NextActions,
  ReportsList,
  Section,
  TasksList,
} from './workspace/Panels';

const TABS = [
  'overview',
  'campaigns',
  'budget',
  'leads',
  'creative',
  'tasks',
  'approvals',
  'reports',
  'activity',
] as const;

type Tab = (typeof TABS)[number];

const READINESS_STATE: Record<string, HealthState> = {
  ready: 'healthy',
  needs_attention: 'attention',
  blocked: 'critical',
};

/**
 * One project, where the team actually works.
 *
 * The scope never leaves the screen — client, project, owner, status, budget, dates, campaign health and
 * readiness sit in the header — and each tab is the project's own slice of a module, read narrowed to it.
 * The right-hand panel keeps the people, the alerts and the latest changes in view while the tabs change,
 * so routine project work does not mean visiting five global pages and rebuilding the context each time.
 */
export function ProjectWorkspacePage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { projectId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const setContext = useUiStore((s) => s.setContext);

  const requested = params.get('tab');
  const tab: Tab = (TABS as readonly string[]).includes(requested ?? '')
    ? (requested as Tab)
    : 'overview';

  const detail = useProjectDetail(projectId);
  const clientId = detail.data?.client.id;
  const readiness = useReadiness(projectId);
  const team = useProjectTeam(projectId);
  const campaigns = useProjectCampaigns(projectId, tab === 'campaigns' ? 25 : 6);
  const budgets = useProjectBudgets(projectId, clientId);
  const leads = useProjectLeads(projectId);
  const tasks = useProjectTasks(projectId);
  const creative = useProjectCreative(projectId, clientId);
  const approvals = useProjectApprovals(projectId, clientId);
  const reports = useClientReports(clientId);
  const activity = useProjectActivity(projectId);
  const attention = useProjectAttention(projectId);

  /* Opening a project makes it the working context, so every global module is already narrowed to it. */
  useEffect(() => {
    if (detail.data !== undefined) {
      setContext({
        client: { id: detail.data.client.id, label: detail.data.client.name },
        project: { id: detail.data.id, label: detail.data.name },
      });
    }
  }, [detail.data, setContext]);

  const project = detail.data;
  const counts = campaigns.data?.counts ?? {};
  const critical = counts['critical'] ?? 0;
  const needing = critical + (counts['needs_attention'] ?? 0);
  const owner =
    team.data?.find((member) => member.role_slug === 'project_manager') ?? team.data?.[0];

  /* A project holds one currency; a budget in another is not folded into its bar. */
  const sameCurrency = (budgets.data?.rows ?? []).filter((b) => b.currency === project?.currency);
  const approved = sameCurrency.reduce((sum, b) => sum + b.approved_minor, 0);
  const spent = sameCurrency.reduce((sum, b) => sum + (b.spent_minor ?? 0), 0);
  const unreported = sameCurrency.some((b) => b.spent_minor === null);
  const share =
    approved === 0 || unreported ? null : Math.min(100, Math.round((spent / approved) * 100));

  const date = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric' });
  const dates =
    project === undefined || (project.starts_on === null && project.ends_on === null)
      ? null
      : [project.starts_on, project.ends_on]
          .map((d) => (d === null ? '…' : date.format(new Date(d))))
          .join(' – ');

  if (detail.isError) {
    return (
      <div className="ds-page">
        <Alert tone="danger">{t('workspace.loadFailed')}</Alert>
      </div>
    );
  }

  const choose = (next: Tab) => {
    setParams(next === 'overview' ? {} : { tab: next }, { replace: true });
  };

  return (
    <div className="ds-page">
      <header className="flex flex-col gap-3">
        <nav aria-label={t('workspace.scope')} className="text-content-tertiary text-xs">
          <Link to="/projects" className="hover:underline">
            {t('nav.projects')}
          </Link>
          {project === undefined ? null : (
            <>
              {' / '}
              <Link to={`/clients/${project.client.id}`} className="hover:underline" dir="auto">
                {project.client.name}
              </Link>
            </>
          )}
        </nav>
        {project === undefined ? (
          <Skeleton className="h-24 w-full" />
        ) : (
          <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-content text-2xl font-semibold" dir="auto">
                {project.name}
              </h1>
              <ProjectStatusBadge status={project.status} />
              {project.is_demo ? <Badge tone="info">{t('workspace.demo')}</Badge> : null}
              <span className="ms-auto flex gap-2">
                <Link
                  to={`/projects/${project.id}/setup`}
                  className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                >
                  {t('workspace.area.setup')}
                </Link>
              </span>
            </div>
            <dl className="grid grid-cols-2 gap-3 xl:grid-cols-5">
              <Fact label={t('workspace.owner')}>
                <span dir="auto">{owner?.name ?? t('attention.unassigned')}</span>
              </Fact>
              <Fact label={t('workspace.dates')}>{dates ?? '—'}</Fact>
              <Fact label={t('workspace.budget')}>
                {approved === 0 ? (
                  '—'
                ) : (
                  <span className="flex flex-col gap-1">
                    <span className="ds-numeric">
                      {unreported ? '—' : formatMinor(spent, project.currency, locale)} /{' '}
                      {formatMinor(approved, project.currency, locale)}
                    </span>
                    {budgets.data?.scope === 'client' ? (
                      <span className="text-content-tertiary text-xs">
                        {t('workspace.clientBudgetShort')}
                      </span>
                    ) : null}
                    {share === null ? null : (
                      <span
                        role="img"
                        aria-label={t('workspace.spentShare', { percent: share })}
                        className="bg-surface-sunken block h-1.5 overflow-hidden rounded-full"
                      >
                        <span
                          className="bg-accent block h-full rounded-full"
                          style={{ width: `${share}%` }}
                        />
                      </span>
                    )}
                  </span>
                )}
              </Fact>
              <Fact label={t('workspace.campaignHealth')}>
                {campaigns.data === undefined ? (
                  '—'
                ) : needing === 0 ? (
                  <HealthPill state="healthy" />
                ) : (
                  <HealthPill
                    state={critical > 0 ? 'critical' : 'attention'}
                    detail={t('workspace.needAttention', { count: needing })}
                  />
                )}
              </Fact>
              <Fact label={t('workspace.readiness')}>
                {readiness.data === undefined ? (
                  '—'
                ) : (
                  <HealthPill
                    state={READINESS_STATE[readiness.data.overall.state] ?? 'unknown'}
                    detail={`${String(readiness.data.ready_count)} / ${String(readiness.data.total)}`}
                  />
                )}
              </Fact>
            </dl>
          </div>
        )}
      </header>

      <div
        role="tablist"
        aria-label={t('workspace.areas')}
        className="border-line-subtle -mx-1 flex gap-1 overflow-x-auto border-b px-1"
      >
        {TABS.map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            id={`ws-tab-${key}`}
            aria-selected={tab === key}
            aria-controls="ws-panel"
            tabIndex={tab === key ? 0 : -1}
            onClick={() => choose(key)}
            onKeyDown={(event) => {
              const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

              if (step !== 0) {
                const rtl = document.documentElement.dir === 'rtl';
                const next =
                  TABS[(TABS.indexOf(key) + (rtl ? -step : step) + TABS.length) % TABS.length];

                if (next !== undefined) {
                  choose(next);
                  document.getElementById(`ws-tab-${next}`)?.focus();
                }
              }
            }}
            className={`focus-visible:ring-accent shrink-0 rounded-t-lg px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none ${
              tab === key
                ? 'border-accent text-content border-b-2'
                : 'text-content-secondary hover:text-content'
            }`}
          >
            {t(`workspace.tab.${key}`)}
          </button>
        ))}
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div
          id="ws-panel"
          role="tabpanel"
          aria-labelledby={`ws-tab-${tab}`}
          className="flex min-w-0 flex-col gap-4"
        >
          {tab === 'overview' ? (
            <>
              <Card>
                <CardHeader>
                  <CardTitle>{t('workspace.nextActions')}</CardTitle>
                </CardHeader>
                <CardContent>
                  {attention.isPending ? (
                    <Skeleton className="h-20" />
                  ) : (
                    <NextActions items={attention.data ?? []} />
                  )}
                </CardContent>
              </Card>
              <div className="grid gap-4 lg:grid-cols-2">
                <Section
                  query={campaigns}
                  title={t('workspace.tab.campaigns')}
                  to={`/projects/${projectId}?tab=campaigns`}
                >
                  {(data) => <CampaignsList data={data} locale={locale} />}
                </Section>
                <Section
                  query={budgets}
                  title={t('workspace.tab.budget')}
                  to={`/projects/${projectId}?tab=budget`}
                >
                  {(data) => <BudgetList rows={data.rows} scope={data.scope} locale={locale} />}
                </Section>
              </div>
              <div className="grid gap-4 lg:grid-cols-2">
                <Section query={leads} title={t('workspace.tab.leads')} to="/leads">
                  {(funnel) => <LeadsSummary funnel={funnel} />}
                </Section>
                <Section query={tasks} title={t('workspace.tab.tasks')} to="/tasks">
                  {(data) => <TasksList data={data} locale={locale} />}
                </Section>
              </div>
            </>
          ) : null}
          {tab === 'campaigns' ? (
            <Section query={campaigns} title={t('workspace.tab.campaigns')} to="/campaigns">
              {(data) => <CampaignsList data={data} locale={locale} />}
            </Section>
          ) : null}
          {tab === 'budget' ? (
            <Section query={budgets} title={t('workspace.tab.budget')} to="/budget-control">
              {(data) => <BudgetList rows={data.rows} scope={data.scope} locale={locale} />}
            </Section>
          ) : null}
          {tab === 'leads' ? (
            <Section query={leads} title={t('workspace.tab.leads')} to="/leads">
              {(funnel) => <LeadsSummary funnel={funnel} />}
            </Section>
          ) : null}
          {tab === 'creative' ? (
            <Section query={creative} title={t('workspace.tab.creative')} to="/creative-hub">
              {(rows) => <CreativeList rows={rows} />}
            </Section>
          ) : null}
          {tab === 'tasks' ? (
            <Section query={tasks} title={t('workspace.tab.tasks')} to="/tasks">
              {(data) => <TasksList data={data} locale={locale} />}
            </Section>
          ) : null}
          {tab === 'approvals' ? (
            <Section query={approvals} title={t('workspace.tab.approvals')} to="/approvals">
              {(rows) => <ApprovalsList rows={rows} />}
            </Section>
          ) : null}
          {tab === 'reports' ? (
            <Section query={reports} title={t('workspace.tab.reports')} to="/reports">
              {(rows) => <ReportsList rows={rows} />}
            </Section>
          ) : null}
          {tab === 'activity' ? (
            <Card>
              <CardHeader>
                <CardTitle>{t('workspace.activity')}</CardTitle>
              </CardHeader>
              <CardContent>
                <Activity query={activity} />
              </CardContent>
            </Card>
          ) : null}
        </div>

        <aside aria-label={t('workspace.context')} className="flex min-w-0 flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle>{t('workspace.team')}</CardTitle>
            </CardHeader>
            <CardContent>
              {team.isPending ? (
                <Skeleton className="h-16" />
              ) : team.data === undefined || team.data.length === 0 ? (
                <p className="text-content-tertiary text-sm">{t('workspace.none.team')}</p>
              ) : (
                <ul className="flex flex-col gap-2">
                  {team.data.map((member) => (
                    <li
                      key={member.user_id}
                      className="flex items-center justify-between gap-2 text-sm"
                    >
                      <span className="min-w-0 truncate" dir="auto">
                        {member.name}
                      </span>
                      <span className="text-content-tertiary shrink-0 text-xs">
                        {member.role_name}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{t('workspace.alerts')}</CardTitle>
            </CardHeader>
            <CardContent>
              {attention.data === undefined ? (
                <Skeleton className="h-12" />
              ) : attention.data.length === 0 ? (
                <p className="text-content-tertiary text-sm">{t('workspace.nothingNext')}</p>
              ) : (
                <p className="text-sm">
                  {t('workspace.alertCount', { count: attention.data.length })}
                </p>
              )}
            </CardContent>
          </Card>
          <BackgroundWork />
          <Card>
            <CardHeader>
              <CardTitle>{t('workspace.changes')}</CardTitle>
            </CardHeader>
            <CardContent>
              <Activity query={activity} />
            </CardContent>
          </Card>
        </aside>
      </div>
    </div>
  );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="bg-surface border-line-subtle min-w-0 rounded-xl border p-3">
      <dt className="ds-overline text-content-tertiary">{label}</dt>
      <dd className="text-content mt-1 min-w-0 text-sm font-medium">{children}</dd>
    </div>
  );
}
