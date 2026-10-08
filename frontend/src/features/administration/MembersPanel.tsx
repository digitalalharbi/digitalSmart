import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { ScrollRegion } from '@/shared/ui/scroll-region';
import { LoadingRegion } from '@/shared/ui/loading-region';
import { AddMemberPanel } from './AddMemberPanel';
import {
  useChangeMemberRoleMutation,
  useChangeMemberStatusMutation,
  useMembersQuery,
  useRemoveMemberMutation,
} from './api';
import { WORKSPACE_ROLES, type Member, type WorkspaceRole } from './types';

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

  const { data, isPending, isError, error } = useMembersQuery({ search, page });
  const changeRole = useChangeMemberRoleMutation();
  const changeStatus = useChangeMemberStatusMutation();
  const removeMember = useRemoveMemberMutation();

  const canUpdate = can(PERMISSIONS.usersUpdate);
  const canInvite = can(PERMISSIONS.usersInvite);
  const canDeactivate = can(PERMISSIONS.usersDeactivate);
  const canRemove = can(PERMISSIONS.workspacesManageMembers);

  // The server refuses to strip the last owner; surfacing that reason here
  // avoids an action that always fails.
  const lastOwnerError =
    [changeRole.error, changeStatus.error, removeMember.error].find(
      (candidate): candidate is ApiError => candidate instanceof ApiError,
    ) ?? null;

  const isSelf = (member: Member): boolean => member.user_id === user?.id;

  return (
    <div className="flex flex-col gap-4">
      {lastOwnerError ? <Alert tone="warning">{lastOwnerError.message}</Alert> : null}

      {/*
        Above the list, because adding somebody is what an administrator opens this page to do and reading the
        list is what they do afterwards. Absent without `users.invite`: the server refuses it anyway, and showing
        a form that always fails is worse than not showing one.
      */}
      {canInvite ? <AddMemberPanel /> : null}

      <div className="relative max-w-xs">
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

      {data && data.data.length === 0 ? <EmptyState title={t('administration.noPeople')} /> : null}

      {data && data.data.length > 0 ? (
        <ScrollRegion
          label={t('administration.people')}
          className="border-line-subtle rounded-xl border"
        >
          <table className="w-full min-w-[48rem] text-sm">
            <caption className="sr-only">{t('administration.people')}</caption>
            <thead>
              <tr className="text-content-tertiary border-line-subtle bg-surface-sunken border-b text-xs tracking-wide uppercase">
                <th scope="col" className="px-4 py-2.5 text-start font-medium">
                  {t('administration.columns.person')}
                </th>
                <th scope="col" className="px-4 py-2.5 text-start font-medium">
                  {t('administration.columns.role')}
                </th>
                <th scope="col" className="px-4 py-2.5 text-start font-medium">
                  {t('administration.columns.status')}
                </th>
                <th scope="col" className="px-4 py-2.5 text-end font-medium">
                  {t('administration.columns.projects')}
                </th>
                <th scope="col" className="px-4 py-2.5 text-end font-medium">
                  <span className="sr-only">{t('administration.columns.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((member) => (
                <tr key={member.user_id} className="border-line-subtle border-b last:border-0">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className="text-content font-medium">{member.name}</span>
                      {isSelf(member) ? <Badge>{t('administration.you')}</Badge> : null}
                    </div>
                    <span className="text-content-tertiary text-xs">{member.email}</span>
                  </td>
                  <td className="px-4 py-3">
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
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={STATUS_TONE[member.status]}>
                      {t(`administration.status.${member.status}`)}
                    </Badge>
                  </td>
                  <td className="text-content ds-numeric px-4 py-3 text-end">
                    {member.project_count}
                  </td>
                  <td className="px-4 py-3">
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
                          disabled={removeMember.isPending}
                          onClick={() => removeMember.mutate(member.user_id)}
                        >
                          {t('actions.remove')}
                        </Button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ScrollRegion>
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
