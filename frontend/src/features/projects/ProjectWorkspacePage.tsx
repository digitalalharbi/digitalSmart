import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Button } from '@/shared/ui/button';
import { PageHeader } from '@/shared/ui/page-header';
import { ActivityFeed } from '@/shared/ui/activity-feed';
import { Skeleton } from '@/shared/ui/skeleton';

type Readiness = {
  project: { id: string; name: string; status: string };
  overall: { state: string; label: string };
  ready_count: number;
  total: number;
};

type ControlMeta = {
  meta: { health_counts: Record<string, number>; total: number; matched: number };
};

type ActivityRow = {
  id: string;
  sentence: string;
  actor: string | null;
  project_id: string | null;
  at: string;
};

type CentreFunnel = { data: { funnel: { total: number; open: number; won: number } } };

/** Where each area of a project lives. Every one opens with the project already chosen as the working context. */
const AREAS: Array<{ key: string; to: string }> = [
  { key: 'setup', to: '/projects/:id/setup' },
  { key: 'campaigns', to: '/campaigns' },
  { key: 'budget', to: '/budget-control' },
  { key: 'tracking', to: '/tracking' },
  { key: 'leads', to: '/leads' },
  { key: 'creative', to: '/creative-hub' },
  { key: 'tasks', to: '/tasks' },
  { key: 'approvals', to: '/approvals' },
  { key: 'reports', to: '/reports' },
];

/**
 * One project, in one place.
 *
 * A hub, not a second copy of each module: the figures here are the server's own answers for *this* project,
 * and every area opens the module that owns it with the project set as the working context — so there is one
 * implementation of campaigns, one of leads, and this page is how you get to them without hunting.
 */
export function ProjectWorkspacePage() {
  const { t } = useTranslation();
  const { projectId = '' } = useParams();
  const navigate = useNavigate();
  const { setContext } = useUiStore();

  const readiness = useQuery({
    queryKey: ['projects', 'readiness', projectId] as const,
    queryFn: async (): Promise<Readiness> => {
      const { data } = await api.get<{ data: Readiness }>(`/projects/${projectId}/readiness`);

      return data.data;
    },
  });

  const campaigns = useQuery({
    queryKey: ['projects', 'workspace', 'campaigns', projectId] as const,
    queryFn: async () => {
      const { data } = await api.get<ControlMeta>('/campaigns/control', {
        params: { project_id: projectId, limit: 1 },
      });

      return data.meta;
    },
    retry: false,
  });

  const leads = useQuery({
    queryKey: ['projects', 'workspace', 'leads', projectId] as const,
    queryFn: async () => {
      const { data } = await api.get<CentreFunnel>('/leads/centre', {
        params: { project_id: projectId },
      });

      return data.data.funnel;
    },
    retry: false,
  });

  const activity = useQuery({
    queryKey: ['projects', 'workspace', 'activity', projectId] as const,
    queryFn: async (): Promise<ActivityRow[]> => {
      const { data } = await api.get<{ data: ActivityRow[] }>('/activity', {
        params: { limit: 100 },
      });

      /* The feed is the agency's recent events within the reader's scope; this page is one project of it. */
      return data.data.filter((row) => row.project_id === projectId).slice(0, 8);
    },
    retry: false,
  });

  const project = readiness.data?.project;

  const open = (to: string) => {
    if (project !== undefined) {
      setContext({ client: null, project: { id: project.id, label: project.name } });
    }

    void navigate(to.replace(':id', projectId));
  };

  const health = campaigns.data?.health_counts ?? {};
  const needing = (health['critical'] ?? 0) + (health['needs_attention'] ?? 0);

  return (
    <div className="ds-page">
      <PageHeader
        title={project?.name ?? t('workspace.title')}
        description={t('workspace.subtitle')}
        actions={
          <Link to="/projects" className={buttonVariants({ variant: 'ghost' })}>
            {t('setup.back')}
          </Link>
        }
      />

      {readiness.isError ? <Alert tone="danger">{t('workspace.loadFailed')}</Alert> : null}

      {readiness.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : (
        <section
          aria-label={t('workspace.glance')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
          <Tile
            label={t('workspace.readiness')}
            value={`${String(readiness.data?.ready_count ?? 0)} / ${String(readiness.data?.total ?? 0)}`}
            note={readiness.data?.overall.label ?? ''}
          />
          <Tile
            label={t('workspace.campaigns')}
            value={campaigns.data === undefined ? '—' : String(campaigns.data.total)}
            note={
              campaigns.data === undefined
                ? t('workspace.unavailable')
                : t('workspace.needAttention', { count: needing })
            }
          />
          <Tile
            label={t('workspace.leads')}
            value={leads.data === undefined ? '—' : String(leads.data.total)}
            note={
              leads.data === undefined
                ? t('workspace.unavailable')
                : t('workspace.leadsOpen', { open: leads.data.open, won: leads.data.won })
            }
          />
          <Tile label={t('workspace.status')} value={project?.status ?? '—'} note="" />
        </section>
      )}

      <section aria-label={t('workspace.activity')} className="flex flex-col gap-2">
        <h2 className="text-content text-sm font-semibold">{t('workspace.activity')}</h2>
        {activity.isError ? null : (
          <ActivityFeed
            isPending={activity.isPending}
            entries={(activity.data ?? []).map((row) => ({
              id: row.id,
              actor: row.actor,
              summary: row.sentence,
              at: row.at,
            }))}
          />
        )}
      </section>

      <nav aria-label={t('workspace.areas')} className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
        {AREAS.map((area) => (
          <Button
            key={area.key}
            variant="secondary"
            className="justify-between"
            onClick={() => open(area.to)}
          >
            {t(`workspace.area.${area.key}`)}
          </Button>
        ))}
      </nav>
    </div>
  );
}

function Tile({ label, value, note }: { label: string; value: string; note: string }) {
  return (
    <div className="bg-surface border-line-subtle rounded-2xl border p-4 shadow-xs">
      <p className="ds-overline text-content-tertiary">{label}</p>
      <p className="ds-numeric text-content mt-1 text-2xl font-semibold">{value}</p>
      {note === '' ? null : <p className="text-content-secondary mt-1 text-xs">{note}</p>}
    </div>
  );
}
