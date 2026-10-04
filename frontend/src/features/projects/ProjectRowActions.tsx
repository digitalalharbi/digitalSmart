import { useState } from 'react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import * as AlertDialog from '@radix-ui/react-alert-dialog';
import { useTranslation } from 'react-i18next';
import { MoreHorizontal } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { Button } from '@/shared/ui/button';
import { buttonVariants } from '@/shared/ui/button.variants';
import { useChangeProjectStatusMutation, useDeleteProjectMutation } from './api';
import { ALLOWED_TRANSITIONS, type Project } from './types';

type ProjectRowActionsProps = {
  project: Project;
  onEdit: (project: Project) => void;
};

export function ProjectRowActions({ project, onEdit }: ProjectRowActionsProps) {
  const { t } = useTranslation();
  const { can } = useSession();
  const changeStatus = useChangeProjectStatusMutation();
  const deleteProject = useDeleteProjectMutation();
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  const canUpdate = can(PERMISSIONS.projectsUpdate);
  const canDelete = can(PERMISSIONS.projectsDelete);
  const transitions = ALLOWED_TRANSITIONS[project.status];

  if (!canUpdate && !canDelete) {
    return null;
  }

  return (
    <>
      <DropdownMenu.Root>
        <DropdownMenu.Trigger asChild>
          <Button
            variant="ghost"
            size="icon"
            aria-label={t('projects.actionsFor', { name: project.name })}
          >
            <MoreHorizontal />
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Portal>
          <DropdownMenu.Content
            align="end"
            sideOffset={4}
            className="bg-surface border-line z-50 min-w-44 rounded-lg border p-1 shadow-md"
          >
            {canUpdate ? (
              <DropdownMenu.Item
                onSelect={() => onEdit(project)}
                className="text-content hover:bg-surface-hover focus:bg-surface-hover cursor-pointer rounded-md px-2.5 py-1.5 text-sm outline-none"
              >
                {t('actions.edit')}
              </DropdownMenu.Item>
            ) : null}

            {canUpdate && transitions.length > 0 ? (
              <>
                <DropdownMenu.Separator className="bg-line-subtle my-1 h-px" />
                <DropdownMenu.Label className="text-content-tertiary px-2.5 py-1 text-xs">
                  {t('projects.changeStatus')}
                </DropdownMenu.Label>
                {transitions.map((status) => (
                  <DropdownMenu.Item
                    key={status}
                    disabled={changeStatus.isPending}
                    onSelect={() => changeStatus.mutate({ id: project.id, status })}
                    className="text-content hover:bg-surface-hover focus:bg-surface-hover cursor-pointer rounded-md px-2.5 py-1.5 text-sm outline-none"
                  >
                    {t(`projects.status.${status}`)}
                  </DropdownMenu.Item>
                ))}
              </>
            ) : null}

            {canDelete ? (
              <>
                <DropdownMenu.Separator className="bg-line-subtle my-1 h-px" />
                <DropdownMenu.Item
                  onSelect={(event) => {
                    event.preventDefault();
                    setConfirmingDelete(true);
                  }}
                  className="text-danger hover:bg-danger-surface focus:bg-danger-surface cursor-pointer rounded-md px-2.5 py-1.5 text-sm outline-none"
                >
                  {t('actions.delete')}
                </DropdownMenu.Item>
              </>
            ) : null}
          </DropdownMenu.Content>
        </DropdownMenu.Portal>
      </DropdownMenu.Root>

      <AlertDialog.Root open={confirmingDelete} onOpenChange={setConfirmingDelete}>
        <AlertDialog.Portal>
          <AlertDialog.Overlay className="bg-overlay fixed inset-0 z-40" />
          <AlertDialog.Content className="bg-surface fixed start-1/2 top-1/2 z-50 w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-xl p-6 shadow-lg rtl:translate-x-1/2">
            <AlertDialog.Title className="text-content text-base font-semibold">
              {t('projects.deleteTitle', { name: project.name })}
            </AlertDialog.Title>
            <AlertDialog.Description className="text-content-secondary mt-2 text-sm leading-relaxed">
              {t('projects.deleteDescription')}
            </AlertDialog.Description>
            <div className="mt-5 flex justify-end gap-2">
              <AlertDialog.Cancel className={buttonVariants({ variant: 'secondary', size: 'md' })}>
                {t('actions.cancel')}
              </AlertDialog.Cancel>
              <AlertDialog.Action
                className={buttonVariants({ variant: 'danger', size: 'md' })}
                onClick={() => deleteProject.mutate(project.id)}
              >
                {t('actions.delete')}
              </AlertDialog.Action>
            </div>
          </AlertDialog.Content>
        </AlertDialog.Portal>
      </AlertDialog.Root>
    </>
  );
}
