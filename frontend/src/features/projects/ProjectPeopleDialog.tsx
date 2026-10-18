import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  useAssignableMembersQuery,
  useAssignMemberMutation,
  useProjectMembersQuery,
  useRemoveMemberMutation,
} from './api';
import type { Project } from './types';
import { returnFocus } from '@/shared/ui/return-focus';

/**
 * Who is on a project, and who could be.
 *
 * **This is the access control for a client's data.** Visibility in this product comes from project assignment
 * and nothing else: a lead, a store's revenue, a campaign's spend, a client's own portal — each is reachable by
 * the people on the project it belongs to, plus whoever holds `projects.view_all`. Until this dialog existed the
 * endpoints were there and nothing reached them, so an administrator could not grant anybody access to
 * anything. The page showed a member count and no way to change it.
 *
 * **The list of candidates is the server's**, and it holds only active members who are not on the project yet —
 * the only set worth offering. A suspended member is absent, and so is somebody already assigned, because
 * offering either produces a no-op the reader has to work out for themselves.
 *
 * **Removing somebody says what it does.** It is the one control here with a consequence that reaches outside
 * this dialog, and the copy names it: they lose sight of this client's work, including anything already in
 * their name.
 */
export function ProjectPeopleDialog({
  project,
  open,
  onOpenChange,
  canManage,
}: {
  project: Project | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  canManage: boolean;
}) {
  const { t } = useTranslation();
  const projectId = project?.id ?? null;

  const members = useProjectMembersQuery(open ? projectId : null);
  const assignable = useAssignableMembersQuery(open ? projectId : null, canManage);
  const assign = useAssignMemberMutation();
  const remove = useRemoveMemberMutation();

  const [chosen, setChosen] = useState('');

  const error = [assign.error, remove.error].find((candidate) => candidate !== null);

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg duration-[var(--ds-duration-base)] rtl:translate-x-1/2"
        >
          <div className="mb-5 flex items-start justify-between gap-4">
            <div>
              <Dialog.Title className="text-content text-lg font-semibold">
                {t('projects.people.title')}
              </Dialog.Title>
              <Dialog.Description className="text-content-secondary mt-1 text-sm">
                {t('projects.people.description', { project: project?.name ?? '' })}
              </Dialog.Description>
            </div>
            <Dialog.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t('actions.close')}>
                <X />
              </Button>
            </Dialog.Close>
          </div>

          <div className="flex flex-col gap-4">
            {error === undefined ? null : (
              <Alert tone="danger">
                {error instanceof ApiError && error.message !== ''
                  ? error.message
                  : t('projects.people.failed')}
              </Alert>
            )}

            {members.isPending ? (
              <div className="flex flex-col gap-2">
                <Skeleton className="h-10 w-full" />
                <Skeleton className="h-10 w-full" />
              </div>
            ) : (members.data ?? []).length === 0 ? (
              /*
               * An empty project is not a neutral state: nobody can see this client's work through it, which is
               * exactly the thing a reader needs told rather than left to infer from a blank list.
               */
              <EmptyState
                title={t('projects.people.emptyTitle')}
                description={t('projects.people.emptyBody')}
              />
            ) : (
              /*
               * Named, so a screen reader — and a test — can tell the people on the project from the picker's
               * options, which carry the same names.
               */
              <ul aria-label={t('projects.people.listLabel')} className="flex flex-col gap-2">
                {(members.data ?? []).map((member) => (
                  <li
                    key={member.user_id}
                    className="border-line-subtle flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                  >
                    <div className="flex flex-col">
                      <span className="text-content text-sm font-medium">{member.name}</span>
                      <span className="text-content-tertiary text-xs">{member.role_name}</span>
                    </div>

                    {canManage ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={remove.isPending}
                        onClick={() => {
                          if (projectId !== null) {
                            remove.mutate({ projectId, userId: member.user_id });
                          }
                        }}
                      >
                        {t('projects.people.remove')}
                      </Button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}

            {!canManage ? null : (
              <form
                className="border-line-subtle flex flex-wrap items-end gap-2 border-t pt-4"
                onSubmit={(event) => {
                  event.preventDefault();

                  const candidate = (assignable.data ?? []).find(
                    (person) => person.user_id === chosen,
                  );

                  if (projectId === null || candidate === undefined) {
                    return;
                  }

                  /*
                   * Their workspace role, rather than a second choice on this form. Somebody is almost always
                   * on a project in the role they hold in the agency, and the role control on the member
                   * directory is where that changes.
                   */
                  assign.mutate(
                    { projectId, userId: candidate.user_id, role: candidate.role_slug },
                    { onSuccess: () => setChosen('') },
                  );
                }}
              >
                <Field label={t('projects.people.add')} hint={t('projects.people.addHint')}>
                  {(props) => (
                    <select
                      {...props}
                      className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
                      value={chosen}
                      onChange={(event) => setChosen(event.target.value)}
                    >
                      <option value="">{t('projects.people.choose')}</option>
                      {(assignable.data ?? []).map((person) => (
                        <option key={person.user_id} value={person.user_id}>
                          {person.name} · {person.role_name}
                        </option>
                      ))}
                    </select>
                  )}
                </Field>

                <Button type="submit" size="sm" disabled={chosen === '' || assign.isPending}>
                  {t('projects.people.grant')}
                </Button>
              </form>
            )}

            <p className="text-content-tertiary text-xs leading-relaxed">
              {t('projects.people.note')}
            </p>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
