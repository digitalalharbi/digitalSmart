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
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import { LoadingRegion } from '@/shared/ui/loading-region';
import { PageHeader } from '@/shared/ui/page-header';
import { useProjectsQuery } from './api';
import { ProjectFormDialog } from './ProjectFormDialog';
import { ProjectPeopleDialog } from './ProjectPeopleDialog';
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
  const [managingPeople, setManagingPeople] = useState<Project | null>(null);
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
        <h1 className="text-content text-2xl font-semibold">{t('nav.projects')}</h1>
        <Alert tone="warning">{t('projects.forbidden')}</Alert>
      </div>
    );
  }

  return (
    <div className="ds-page">
      <PageHeader
        title={t('nav.projects')}
        description={
          isAssignedOnly
            ? t('projects.assignedOnly')
            : data
              ? t('projects.count', { count: data.meta.total })
              : t('projects.subtitle')
        }
        primaryAction={
          can(PERMISSIONS.projectsCreate) ? (
            <Button onClick={openCreate}>
              <Plus />
              {t('projects.create')}
            </Button>
          ) : undefined
        }
      />

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
        <LoadingRegion label={t('projects.loading')} className="flex flex-col gap-2">
          {Array.from({ length: 5 }, (_, index) => (
            <Skeleton key={index} className="h-14" />
          ))}
        </LoadingRegion>
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
          <DataTable label={t('nav.projects')} minWidth="52rem">
            <TableHead>
              <TableRow>
                <TableHeader>{t('projects.columns.name')}</TableHeader>
                <TableHeader>{t('projects.columns.client')}</TableHeader>
                <TableHeader>{t('projects.columns.status')}</TableHeader>
                <TableHeader>{t('projects.columns.schedule')}</TableHeader>
                <TableHeader align="end">{t('projects.columns.members')}</TableHeader>
                <TableHeader align="end">
                  <span className="sr-only">{t('projects.columns.actions')}</span>
                </TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {data.data.map((project) => (
                <TableRow key={project.id} isInteractive>
                  <TableCell>
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
                  </TableCell>
                  <TableCell className="text-content-secondary">{project.client.name}</TableCell>
                  <TableCell>
                    <ProjectStatusBadge status={project.status} />
                  </TableCell>
                  <TableCell className="text-content-secondary text-xs" isNumeric>
                    {project.starts_on ?? '—'} → {project.ends_on ?? '—'}
                  </TableCell>
                  <TableCell align="end" isNumeric>
                    {/*
                      The count was the whole of this column and there was no way to change it — so the number
                      that decides who can see a client's work was read-only. It is a button now, because the
                      answer to "who can see this" is the thing somebody came to this page to change.
                    */}
                    <button
                      type="button"
                      onClick={() => setManagingPeople(project)}
                      className="text-content-brand hover:underline focus-visible:ring-2 focus-visible:ring-[var(--ds-focus-ring)] focus-visible:outline-none"
                      aria-label={t('projects.people.open')}
                    >
                      {formatNumber(project.member_count, locale)}
                    </button>
                  </TableCell>
                  <TableCell align="end">
                    <ProjectRowActions
                      project={project}
                      onEdit={(value) => {
                        setEditing(value);
                        setFormOpen(true);
                      }}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </DataTable>

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

      {/*
        Keyed by the project, so opening a second one does not inherit the first's half-made choice. The dialog
        asks for nothing until it is open, which is why the queries behind it are gated on that.
      */}
      <ProjectPeopleDialog
        key={managingPeople?.id ?? 'none'}
        project={managingPeople}
        open={managingPeople !== null}
        onOpenChange={(open) => {
          if (!open) {
            setManagingPeople(null);
          }
        }}
        canManage={can(PERMISSIONS.projectsManageMembers)}
      />
    </div>
  );
}
