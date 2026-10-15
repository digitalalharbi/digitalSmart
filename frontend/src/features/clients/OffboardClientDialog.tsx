import * as Dialog from '@radix-ui/react-dialog';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Skeleton } from '@/shared/ui/skeleton';
import { Textarea } from '@/shared/ui/textarea';
import { useOffboardClientMutation, useOffboardingPreview } from './api';
import type { Client } from './types';

/**
 * A client leaves. Everything recurring stops; nothing they produced goes.
 *
 * The preview is read *before* the decision and has the same shape as what the act then does, so the list a
 * person confirms is a description of what will happen. A reason is required: this is the one act in the
 * product that is hard to walk back from, and the next person to open the client should be able to see why.
 */
export function OffboardClientDialog({
  client,
  onClose,
}: {
  client: Client | null;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const preview = useOffboardingPreview(client?.id ?? null);
  const offboard = useOffboardClientMutation();
  const [reason, setReason] = useState('');

  const lines =
    preview.data === undefined
      ? []
      : ([
          ['projects', preview.data.projects_closing],
          ['bindings', preview.data.bindings_paused],
          ['connections', preview.data.connections_disconnected],
          ['shares', preview.data.share_links_revoked],
        ] as const);

  return (
    <Dialog.Root open={client !== null} onOpenChange={(open) => (open ? undefined : onClose())}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40" />
        <Dialog.Content className="bg-surface fixed start-1/2 top-1/2 z-50 w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-xl p-6 shadow-lg rtl:translate-x-1/2">
          <Dialog.Title className="text-content text-lg font-semibold">
            {t('offboard.title', { name: client?.name ?? '' })}
          </Dialog.Title>
          <Dialog.Description className="text-content-secondary mt-1 text-sm">
            {t('offboard.body')}
          </Dialog.Description>

          <div className="mt-4">
            {preview.isPending ? (
              <Skeleton className="h-24" />
            ) : (
              <ul className="flex flex-col gap-1.5 text-sm" aria-label={t('offboard.willStop')}>
                {lines.map(([key, count]) => (
                  <li key={key} className="flex justify-between">
                    <span>{t(`offboard.line.${key}`)}</span>
                    <span className="ds-numeric">{count}</span>
                  </li>
                ))}
                {preview.data?.portal_suspended === true ? (
                  <li className="flex justify-between">
                    <span>{t('offboard.line.portal')}</span>
                    <span>✓</span>
                  </li>
                ) : null}
              </ul>
            )}
          </div>

          <p className="text-content-tertiary mt-3 text-xs">{t('offboard.kept')}</p>

          <form
            className="mt-4 flex flex-col gap-3"
            onSubmit={(event) => {
              event.preventDefault();

              if (client !== null) {
                offboard.mutate(
                  { id: client.id, reason },
                  {
                    onSuccess: () => {
                      setReason('');
                      onClose();
                    },
                  },
                );
              }
            }}
          >
            <Field label={t('offboard.reason')} required>
              {(field) => (
                <Textarea
                  {...field}
                  value={reason}
                  onChange={(event) => setReason(event.target.value)}
                  rows={3}
                />
              )}
            </Field>

            {offboard.isError ? (
              <Alert tone="danger">
                {offboard.error instanceof ApiError ? offboard.error.message : t('offboard.failed')}
              </Alert>
            ) : null}

            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={onClose}>
                {t('actions.cancel')}
              </Button>
              <Button
                type="submit"
                variant="danger"
                disabled={reason.trim().length < 3 || offboard.isPending}
              >
                {t('offboard.confirm')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
