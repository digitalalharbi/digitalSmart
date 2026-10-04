import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, Search } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { formatNumber } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { useProjectsQuery } from './api';
import { ProjectFormDialog } from './ProjectFormDialog';
import { ProjectRowActions } from './ProjectRowActions';
import { ProjectStatusBadge } from './ProjectStatusBadge';
import { PROJECT_STATUSES, type Project, type ProjectStatus } from './types';

const PER_PAGE = 25;

export function ProjectsPage() {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const { can } = useSession();

  const [search, setSearch] = useState('');
  const [statuses, setStatuses] = useState<ProjectStatus[]>([]);
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Project | undefined>(undefined);
  const [isFormOpen, setFormOpen] = useState(false);

  const params = useMemo(
    () => ({ search, status: statuses, page, per_page: PER_PAGE }),
    [search, statuses, page],
  );
  const { data, isPending, isError, error } = useProjectsQuery(params);

  // Someone who only sees assigned projects should understand why the list is
  // short, rather than assuming the workspace is empty.
  const isAssignedOnly = !can(PERMISSIONS.projectsViewAll);

  const toggleStatus = (status: ProjectStatus) => {
    setPage(1);
    setStatuses((current) =>
      current.includes(status) ? current.filter((value) => value !== status) : [...current, status],
    );
  };

  const openCreate = () => {
    setEditing(undefined);
    setFormOpen(true);
  };

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-content text-2xl font-semibold tracking-[-0.02em]">
          {t('nav.projects')}
        </h1>
        <Alert tone="warning">{t('projects.forbidden')}</Alert>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-content text-2xl font-semibold tracking-[-0.02em]">
            {t('nav.projects')}
          </h1>
          <p className="text-content-secondary text-sm">
            {isAssignedOnly
              ? t('projects.assignedOnly')
              : data
                ? t('projects.count', { count: data.meta.total })
                : t('projects.subtitle')}
          </p>
        </div>

        {can(PERMISSIONS.projectsCreate) ? (
          <Button onClick={openCreate}>
            <Plus />
            {t('projects.create')}
          </Button>
        ) : null}
      </header>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-0 flex-1 sm:max-w-xs">
          <Search
            className="text-content-tertiary pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
            aria-hidden="true"
          />
          <Input
            value={search}
            onChange={(event) => {
              setPage(1);
              setSearch(event.target.value);
            }}
            placeholder={t('projects.searchPlaceholder')}
            aria-label={t('projects.searchPlaceholder')}
            className="ps-9"
            type="search"
          />
        </div>

        <div
          className="flex flex-wrap gap-1.5"
          role="group"
          aria-label={t('projects.filterByStatus')}
        >
          {PROJECT_STATUSES.map((status) => {
            const active = statuses.includes(status);
            return (
              <Button
                key={status}
                size="sm"
                variant={active ? 'subtle' : 'ghost'}
                aria-pressed={active}
                onClick={() => toggleStatus(status)}
              >
                {t(`projects.status.${status}`)}
              </Button>
            );
          })}
        </div>
      </div>

      {isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-label={t('projects.loading')}>
          {Array.from({ length: 5 }, (_, index) => (
            <Skeleton key={index} className="h-14" />
          ))}
        </div>
      ) : null}

      {isError && !(error instanceof ApiError && error.status === 403) ? (
        <Alert tone="danger">
          {error instanceof ApiError ? error.message : t('projects.loadFailed')}
        </Alert>
      ) : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title={
            search || statuses.length
              ? t('projects.noMatches')
              : isAssignedOnly
                ? t('projects.noneAssigned')
                : t('projects.empty')
          }
          description={
            search || statuses.length
              ? t('projects.noMatchesHint')
              : isAssignedOnly
                ? t('projects.noneAssignedHint')
                : t('projects.emptyHint')
          }
          action={
            can(PERMISSIONS.projectsCreate) && !search && statuses.length === 0 ? (
              <Button onClick={openCreate}>
                <Plus />
                {t('projects.create')}
              </Button>
            ) : undefined
          }
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <>
          <div className="border-line-subtle overflow-x-auto rounded-xl border">
            <table className="w-full min-w-[52rem] text-sm">
              <caption className="sr-only">{t('nav.projects')}</caption>
              <thead>
                <tr className="text-content-tertiary border-line-subtle bg-surface-sunken border-b text-xs tracking-wide uppercase">
                  <th scope="col" className="px-4 py-2.5 text-start font-medium">
                    {t('projects.columns.name')}
                  </th>
                  <th scope="col" className="px-4 py-2.5 text-start font-medium">
                    {t('projects.columns.client')}
                  </th>
                  <th scope="col" className="px-4 py-2.5 text-start font-medium">
                    {t('projects.columns.status')}
                  </th>
                  <th scope="col" className="px-4 py-2.5 text-start font-medium">
                    {t('projects.columns.schedule')}
                  </th>
                  <th scope="col" className="px-4 py-2.5 text-end font-medium">
                    {t('projects.columns.members')}
                  </th>
                  <th scope="col" className="px-4 py-2.5 text-end font-medium">
                    <span className="sr-only">{t('projects.columns.actions')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((project) => (
                  <tr
                    key={project.id}
                    className="border-line-subtle hover:bg-surface-hover border-b transition-colors last:border-0"
                  >
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-content font-medium">{project.name}</span>
                        {project.is_demo ? <Badge tone="warning">{t('status.demo')}</Badge> : null}
                        {project.is_assigned ? (
                          <Badge tone="accent">{t('projects.assigned')}</Badge>
                        ) : null}
                      </div>
                      <span className="text-content-tertiary ds-numeric text-xs">
                        {project.currency}
                      </span>
                    </td>
                    <td className="text-content-secondary px-4 py-3">{project.client.name}</td>
                    <td className="px-4 py-3">
                      <ProjectStatusBadge status={project.status} />
                    </td>
                    <td className="text-content-secondary ds-numeric px-4 py-3 text-xs">
                      {project.starts_on ?? '—'} → {project.ends_on ?? '—'}
                    </td>
                    <td className="text-content ds-numeric px-4 py-3 text-end">
                      {formatNumber(project.member_count, locale)}
                    </td>
                    <td className="px-4 py-3 text-end">
                      <ProjectRowActions
                        project={project}
                        onEdit={(value) => {
                          setEditing(value);
                          setFormOpen(true);
                        }}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {data.meta.last_page > 1 ? (
            <nav
              className="flex items-center justify-between gap-4"
              aria-label={t('projects.pagination')}
            >
              <p className="text-content-secondary text-sm">
                {t('clients.pageOf', { page: data.meta.page, total: data.meta.last_page })}
              </p>
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={data.meta.page <= 1}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  {t('actions.previous')}
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={!data.meta.has_more}
                  onClick={() => setPage((current) => current + 1)}
                >
                  {t('actions.next')}
                </Button>
              </div>
            </nav>
          ) : null}
        </>
      ) : null}

      <ProjectFormDialog open={isFormOpen} onOpenChange={setFormOpen} project={editing} />
    </div>
  );
}
