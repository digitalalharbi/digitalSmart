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
import { PageHeader } from '@/shared/ui/page-header';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import { LoadingRegion } from '@/shared/ui/loading-region';
import { useClientsQuery } from './api';
import { ClientFormDialog } from './ClientFormDialog';
import { ClientCard } from './ClientCard';
import { ClientRowActions } from './ClientRowActions';
import { StatusBadge } from './StatusBadge';
import { CLIENT_STATUSES, type Client, type ClientStatus } from './types';

const PER_PAGE = 25;

export function ClientsPage() {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const { can } = useSession();

  const [view, setView] = useState<'grid' | 'table'>('grid');
  const [search, setSearch] = useState('');
  const [statuses, setStatuses] = useState<ClientStatus[]>([]);
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Client | undefined>(undefined);
  const [isFormOpen, setFormOpen] = useState(false);

  const params = useMemo(
    () => ({ search, status: statuses, page, per_page: PER_PAGE }),
    [search, statuses, page],
  );
  const { data, isPending, isError, error, isFetching } = useClientsQuery(params);

  const toggleStatus = (status: ClientStatus) => {
    setPage(1);
    setStatuses((current) =>
      current.includes(status) ? current.filter((value) => value !== status) : [...current, status],
    );
  };

  const openCreate = () => {
    setEditing(undefined);
    setFormOpen(true);
  };

  const openEdit = (client: Client) => {
    setEditing(client);
    setFormOpen(true);
  };

  // A 403 means the role cannot see clients at all — a different message from
  // a transport failure, and not something a retry will fix.
  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-content text-2xl font-semibold">{t('nav.clients')}</h1>
        <Alert tone="warning">{t('clients.forbidden')}</Alert>
      </div>
    );
  }

  return (
    <div className="ds-page">
      <PageHeader
        title={t('nav.clients')}
        description={data ? t('clients.count', { count: data.meta.total }) : t('clients.subtitle')}
        primaryAction={
          can(PERMISSIONS.clientsCreate) ? (
            <Button onClick={openCreate}>
              <Plus />
              {t('clients.create')}
            </Button>
          ) : undefined
        }
        filters={
          <>
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
                placeholder={t('clients.searchPlaceholder')}
                aria-label={t('clients.searchPlaceholder')}
                className="ps-9"
                type="search"
              />
            </div>

            {/*
              Cards first, deliberately. A client list is a list of relationships; the table is there for the
              moment somebody wants to compare twenty of them, which is a different question.
            */}
            <div
              className="flex flex-wrap gap-1.5"
              role="group"
              aria-label={t('clients.card.view')}
            >
              {(['grid', 'table'] as const).map((option) => (
                <Button
                  key={option}
                  size="sm"
                  variant={view === option ? 'subtle' : 'ghost'}
                  aria-pressed={view === option}
                  onClick={() => setView(option)}
                >
                  {t(`clients.card.${option}`)}
                </Button>
              ))}
            </div>

            <div
              className="flex flex-wrap gap-1.5"
              role="group"
              aria-label={t('clients.filterByStatus')}
            >
              {CLIENT_STATUSES.map((status) => {
                const active = statuses.includes(status);
                return (
                  <Button
                    key={status}
                    size="sm"
                    variant={active ? 'subtle' : 'ghost'}
                    aria-pressed={active}
                    onClick={() => toggleStatus(status)}
                  >
                    {t(`clients.status.${status}`)}
                  </Button>
                );
              })}
            </div>
          </>
        }
      />

      {isPending ? (
        <LoadingRegion label={t('clients.loading')} className="flex flex-col gap-2">
          {Array.from({ length: 5 }, (_, index) => (
            <Skeleton key={index} className="h-14" />
          ))}
        </LoadingRegion>
      ) : null}

      {isError && !(error instanceof ApiError && error.status === 403) ? (
        <Alert tone="danger">
          {error instanceof ApiError ? error.message : t('clients.loadFailed')}
        </Alert>
      ) : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title={search || statuses.length ? t('clients.noMatches') : t('clients.empty')}
          description={
            search || statuses.length ? t('clients.noMatchesHint') : t('clients.emptyHint')
          }
          action={
            can(PERMISSIONS.clientsCreate) && !search && statuses.length === 0 ? (
              <Button onClick={openCreate}>
                <Plus />
                {t('clients.create')}
              </Button>
            ) : undefined
          }
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <>
          {view === 'grid' ? (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {data.data.map((client) => (
                <ClientCard key={client.id} client={client} onEdit={openEdit} />
              ))}
            </div>
          ) : (
            <DataTable
              label={t('nav.clients')}
              minWidth="46rem"
              data-fetching={isFetching ? 'true' : undefined}
            >
              <TableHead>
                <TableRow>
                  <TableHeader>{t('clients.columns.name')}</TableHeader>
                  <TableHeader>{t('clients.columns.status')}</TableHeader>
                  <TableHeader>{t('clients.columns.industry')}</TableHeader>
                  <TableHeader align="end">{t('clients.columns.projects')}</TableHeader>
                  <TableHeader align="end">
                    <span className="sr-only">{t('clients.columns.actions')}</span>
                  </TableHeader>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.data.map((client) => (
                  <TableRow key={client.id} isInteractive>
                    <TableCell label={t('clients.columns.name')}>
                      <div className="flex items-center gap-2">
                        <span className="text-content font-medium">{client.name}</span>
                        {client.is_demo ? <Badge tone="warning">{t('status.demo')}</Badge> : null}
                      </div>
                      {client.primary_contact_email ? (
                        <span className="text-content-tertiary text-xs">
                          {client.primary_contact_email}
                        </span>
                      ) : null}
                    </TableCell>
                    <TableCell label={t('clients.columns.status')}>
                      <StatusBadge status={client.status} />
                    </TableCell>
                    <TableCell
                      label={t('clients.columns.industry')}
                      className="text-content-secondary"
                    >
                      {client.industry ?? '—'}
                    </TableCell>
                    <TableCell label={t('clients.columns.projects')} align="end" isNumeric>
                      {formatNumber(client.project_count, locale)}
                    </TableCell>
                    <TableCell align="end">
                      <ClientRowActions client={client} onEdit={openEdit} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </DataTable>
          )}

          {data.meta.last_page > 1 ? (
            <nav
              className="flex items-center justify-between gap-4"
              aria-label={t('clients.pagination')}
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

      <ClientFormDialog open={isFormOpen} onOpenChange={setFormOpen} client={editing} />
    </div>
  );
}
