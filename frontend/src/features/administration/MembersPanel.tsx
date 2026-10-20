import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import { LoadingRegion } from '@/shared/ui/loading-region';
import { AddMemberPanel } from './AddMemberPanel';
import { MembersGlance } from './MembersGlance';
import { RemoveMemberDialog } from './RemoveMemberDialog';
import { useChangeMemberRoleMutation, useChangeMemberStatusMutation, useMembersQuery } from './api';
import {
  FILTERABLE_STATUSES,
  WORKSPACE_ROLES,
  type Member,
  type MembershipStatus,
  type WorkspaceRole,
} from './types';

const STATUS_TONE = {
  active: 'success',
  invited: 'accent',
  suspended: 'danger',
} as const;

export function MembersPanel() {
  const { t } = useTranslation();
  const { user, can } = useSession();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  /*
   * Both filters are the server's — `ListMembers` has taken a role and a status since the module was built,
   * and nothing sent either. A directory that can only be searched by name cannot answer "who is suspended",
   * which is the question the removal and suspension controls beside it create.
   */
  const [status, setStatus] = useState<MembershipStatus | ''>('');
  const [role, setRole] = useState<WorkspaceRole | ''>('');

  const { data, isPending, isError, error } = useMembersQuery({ search, status, role, page });
  const changeRole = useChangeMemberRoleMutation();
  const changeStatus = useChangeMemberStatusMutation();
  /*
   * Removal goes through a dialog rather than a click, because it is irreversible and it releases everything
   * still open in that person's name. The dialog reads what they hold and says so first — see
   * `RemoveMemberDialog`.
   */
  const [removing, setRemoving] = useState<Member | null>(null);

  const canUpdate = can(PERMISSIONS.usersUpdate);
  const canInvite = can(PERMISSIONS.usersInvite);
  const canDeactivate = can(PERMISSIONS.usersDeactivate);
  const canRemove = can(PERMISSIONS.workspacesManageMembers);

  // The server refuses to strip the last owner; surfacing that reason here
  // avoids an action that always fails.
  const lastOwnerError =
    [changeRole.error, changeStatus.error].find(
      (candidate): candidate is ApiError => candidate instanceof ApiError,
    ) ?? null;

  const isSelf = (member: Member): boolean => member.user_id === user?.id;

  return (
    <div className="flex flex-col gap-4">
      {lastOwnerError ? <Alert tone="warning">{lastOwnerError.message}</Alert> : null}

      <RemoveMemberDialog
        member={removing}
        onOpenChange={(open) => {
          if (!open) {
            setRemoving(null);
          }
        }}
      />

      {/*
        Above the list, because adding somebody is what an administrator opens this page to do and reading the
        list is what they do afterwards. Absent without `users.invite`: the server refuses it anyway, and showing
        a form that always fails is worse than not showing one.
      */}
      <MembersGlance />

      {canInvite ? <AddMemberPanel /> : null}

      <div className="flex flex-wrap items-end gap-3">
        <div className="relative max-w-xs flex-1">
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
            placeholder={t('administration.searchPeople')}
            aria-label={t('administration.searchPeople')}
            className="ps-9"
            type="search"
          />
        </div>

        {/*
          `Field` rather than a hand-written label wrapping the select. A wrapping `<label>` puts the control
          inside its own text, so the accessible name becomes "Status Any status" — which is both wrong to hear
          and untestable by name.
        */}
        <Field label={t('administration.filters.status')}>
          {(props) => (
            <select
              {...props}
              className="border-line bg-surface text-content h-9 rounded-lg border px-2 text-sm"
              value={status}
              onChange={(event) => {
                setPage(1);
                setStatus(event.target.value as MembershipStatus | '');
              }}
            >
              <option value="">{t('administration.filters.anyStatus')}</option>
              {FILTERABLE_STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`administration.status.${value}`)}
                </option>
              ))}
            </select>
          )}
        </Field>

        <Field label={t('administration.filters.role')}>
          {(props) => (
            <select
              {...props}
              className="border-line bg-surface text-content h-9 rounded-lg border px-2 text-sm"
              value={role}
              onChange={(event) => {
                setPage(1);
                setRole(event.target.value as WorkspaceRole | '');
              }}
            >
              <option value="">{t('administration.filters.anyRole')}</option>
              {WORKSPACE_ROLES.map((value) => (
                <option key={value} value={value}>
                  {t(`administration.roles.${value}`)}
                </option>
              ))}
            </select>
          )}
        </Field>
      </div>

      {isPending ? (
        <LoadingRegion label={t('administration.loadingPeople')} className="flex flex-col gap-2">
          {Array.from({ length: 4 }, (_, index) => (
            <Skeleton key={index} className="h-14" />
          ))}
        </LoadingRegion>
      ) : null}

      {isError ? (
        <Alert tone="danger">
          {error instanceof ApiError ? error.message : t('administration.loadFailed')}
        </Alert>
      ) : null}

      {data && data.data.length === 0 ? (
        /* Which of the three narrowed it to nothing is the part a reader cannot see from an empty table. */
        search === '' && status === '' && role === '' ? (
          <EmptyState title={t('administration.noPeople')} />
        ) : (
          <EmptyState
            title={t('administration.noPeople')}
            description={t('administration.noPeopleFiltered')}
          />
        )
      ) : null}

      {data && data.data.length > 0 ? (
        <DataTable label={t('administration.people')} minWidth="48rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('administration.columns.person')}</TableHeader>
              <TableHeader>{t('administration.columns.role')}</TableHeader>
              <TableHeader>{t('administration.columns.status')}</TableHeader>
              <TableHeader align="end">{t('administration.columns.projects')}</TableHeader>
              <TableHeader align="end">
                <span className="sr-only">{t('administration.columns.actions')}</span>
              </TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {data.data.map((member) => (
              <TableRow key={member.user_id} isInteractive>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <span className="text-content font-medium">{member.name}</span>
                    {isSelf(member) ? <Badge>{t('administration.you')}</Badge> : null}
                  </div>
                  <span className="text-content-tertiary text-xs">{member.email}</span>
                </TableCell>
                <TableCell>
                  {canUpdate ? (
                    <select
                      value={member.role}
                      aria-label={t('administration.roleFor', { name: member.name })}
                      disabled={changeRole.isPending}
                      onChange={(event) =>
                        changeRole.mutate({
                          userId: member.user_id,
                          role: event.target.value as WorkspaceRole,
                        })
                      }
                      className="border-line bg-surface text-content h-8 rounded-md border px-2 text-sm"
                    >
                      {WORKSPACE_ROLES.map((role) => (
                        <option key={role} value={role}>
                          {t(`administration.roles.${role}`)}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <span className="text-content-secondary">{member.role_name}</span>
                  )}
                </TableCell>
                <TableCell>
                  <Badge tone={STATUS_TONE[member.status]}>
                    {t(`administration.status.${member.status}`)}
                  </Badge>
                </TableCell>
                <TableCell align="end" isNumeric>
                  {member.project_count}
                </TableCell>
                <TableCell>
                  <div className="flex justify-end gap-1.5">
                    {canDeactivate ? (
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={changeStatus.isPending}
                        onClick={() =>
                          changeStatus.mutate({
                            userId: member.user_id,
                            status: member.status === 'suspended' ? 'active' : 'suspended',
                          })
                        }
                      >
                        {member.status === 'suspended'
                          ? t('administration.reinstate')
                          : t('administration.suspend')}
                      </Button>
                    ) : null}
                    {canRemove && !isSelf(member) ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="text-danger"
                        onClick={() => setRemoving(member)}
                      >
                        {t('actions.remove')}
                      </Button>
                    ) : null}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      ) : null}

      {data && data.meta.last_page > 1 ? (
        <div className="flex justify-end gap-2">
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
      ) : null}
    </div>
  );
}
