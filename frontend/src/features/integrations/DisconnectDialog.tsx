import * as Dialog from '@radix-ui/react-dialog';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { returnFocus } from '@/shared/ui/return-focus';

/**
 * Disconnecting, with what it does and does not do said before the button.
 *
 * Both disconnect buttons used to act on a single click, beside controls that only read. What the action *is* matters here
 * because three different things share the word: the credentials stored in this product are destroyed (that is what happens);
 * the access the client or the agency granted at the provider itself is not revoked from here (it stays until somebody removes
 * it there); and what was already read — orders, figures, accounts, project bindings — stays exactly as it was. Saying the
 * second one is the point: an operator retiring a client's account who believes this closes the door at the provider is wrong.
 */
export function DisconnectDialog({
  name,
  open,
  pending,
  error,
  onConfirm,
  onOpenChange,
}: {
  name: string;
  open: boolean;
  pending: boolean;
  error: unknown;
  onConfirm: () => void;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg duration-[var(--ds-duration-base)] rtl:translate-x-1/2"
        >
          <Dialog.Title className="text-content text-lg font-semibold">
            {t('integrations.disconnectDialog.title', { name })}
          </Dialog.Title>
          <Dialog.Description className="text-content-secondary mt-1 text-sm">
            {t('integrations.disconnectDialog.body')}
          </Dialog.Description>

          <ul className="text-content-secondary mt-4 flex list-disc flex-col gap-1.5 ps-5 text-sm">
            <li>{t('integrations.disconnectDialog.stops')}</li>
            <li>{t('integrations.disconnectDialog.credentials')}</li>
            <li>{t('integrations.disconnectDialog.kept')}</li>
            <li>{t('integrations.disconnectDialog.provider')}</li>
          </ul>

          {error !== null && error !== undefined ? (
            <Alert tone="danger" className="mt-4">
              {error instanceof ApiError && error.message !== ''
                ? error.message
                : t('integrations.disconnectDialog.failed')}
            </Alert>
          ) : null}

          <div className="mt-5 flex justify-end gap-2">
            <Dialog.Close asChild>
              <Button type="button" variant="secondary">
                {t('actions.cancel')}
              </Button>
            </Dialog.Close>
            <Button type="button" variant="danger" disabled={pending} onClick={onConfirm}>
              {t('integrations.disconnectDialog.confirm')}
            </Button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
