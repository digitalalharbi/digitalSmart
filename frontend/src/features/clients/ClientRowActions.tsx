import { useState } from 'react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import * as AlertDialog from '@radix-ui/react-alert-dialog';
import { useTranslation } from 'react-i18next';
import { MoreHorizontal } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { Button } from '@/shared/ui/button';
import { buttonVariants } from '@/shared/ui/button.variants';
import { useChangeClientStatusMutation, useDeleteClientMutation } from './api';
import { OffboardClientDialog } from './OffboardClientDialog';
import { ALLOWED_TRANSITIONS, type Client } from './types';

type ClientRowActionsProps = {
  client: Client;
  onEdit: (client: Client) => void;
};

/**
 * Row menu.
 *
 * Only transitions the domain permits are offered, and only actions the role
 * allows are rendered — but the server re-checks both.
 */
export function ClientRowActions({ client, onEdit }: ClientRowActionsProps) {
  const { t } = useTranslation();
  const { can } = useSession();
  const changeStatus = useChangeClientStatusMutation();
  const deleteClient = useDeleteClientMutation();
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [offboarding, setOffboarding] = useState(false);

  const canUpdate = can(PERMISSIONS.clientsUpdate);
  const canDelete = can(PERMISSIONS.clientsDelete);
  const transitions = ALLOWED_TRANSITIONS[client.status];

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
            aria-label={t('clients.actionsFor', { name: client.name })}
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
                onSelect={() => onEdit(client)}
                className="text-content hover:bg-surface-hover focus:bg-surface-hover cursor-pointer rounded-md px-2.5 py-1.5 text-sm outline-none"
              >
                {t('actions.edit')}
              </DropdownMenu.Item>
            ) : null}

            {canUpdate && transitions.length > 0 ? (
              <>
                <DropdownMenu.Separator className="bg-line-subtle my-1 h-px" />
                <DropdownMenu.Label className="text-content-tertiary px-2.5 py-1 text-xs">
                  {t('clients.changeStatus')}
                </DropdownMenu.Label>
                {transitions.map((status) => (
                  <DropdownMenu.Item
                    key={status}
                    disabled={changeStatus.isPending}
                    onSelect={() => {
                      /* Leaving is not a status toggle: it stops things, so it shows what and asks why. */
                      if (status === 'offboarded') {
                        setOffboarding(true);

                        return;
                      }

                      changeStatus.mutate({ id: client.id, status });
                    }}
                    className="text-content hover:bg-surface-hover focus:bg-surface-hover cursor-pointer rounded-md px-2.5 py-1.5 text-sm outline-none"
                  >
                    {t(`clients.status.${status}`)}
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

      <OffboardClientDialog
        client={offboarding ? client : null}
        onClose={() => setOffboarding(false)}
      />

      <AlertDialog.Root open={confirmingDelete} onOpenChange={setConfirmingDelete}>
        <AlertDialog.Portal>
          <AlertDialog.Overlay className="bg-overlay fixed inset-0 z-40" />
          <AlertDialog.Content className="bg-surface fixed start-1/2 top-1/2 z-50 w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-xl p-6 shadow-lg rtl:translate-x-1/2">
            <AlertDialog.Title className="text-content text-base font-semibold">
              {t('clients.deleteTitle', { name: client.name })}
            </AlertDialog.Title>
            <AlertDialog.Description className="text-content-secondary mt-2 text-sm leading-relaxed">
              {t('clients.deleteDescription')}
            </AlertDialog.Description>
            <div className="mt-5 flex justify-end gap-2">
              <AlertDialog.Cancel className={buttonVariants({ variant: 'secondary', size: 'md' })}>
                {t('actions.cancel')}
              </AlertDialog.Cancel>
              <AlertDialog.Action
                className={buttonVariants({ variant: 'danger', size: 'md' })}
                onClick={() => deleteClient.mutate(client.id)}
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
