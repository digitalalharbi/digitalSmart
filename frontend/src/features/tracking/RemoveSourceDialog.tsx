import * as Dialog from '@radix-ui/react-dialog';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { useDeleteSourceMutation } from './useTracking';
import type { MeasurementSource } from './types';
import { returnFocus } from '@/shared/ui/return-focus';

/**
 * Stop measuring through something, with what that costs stated first.
 *
 * `DELETE /api/measurement/sources/{id}` existed, was tested and had a mutation hook with no caller: a source
 * could be registered and never removed. A mistyped property, a container belonging to a client the agency no
 * longer works with, a GA4 roll-up registered beside the properties it aggregates — each was permanent, and the
 * last of those double-counts every figure it touches.
 *
 * **The cascade is named, because it is not obvious and it is not small.** The day-by-day counts read through
 * this source go with it, which is the right behaviour — those numbers only ever meant anything *as read through
 * here*, and orphans would leave a client's history including events from a property nobody can look up any
 * more. Conversion definitions survive but lose the mapping that named this source. Leads it reported keep their
 * record and stop naming it.
 *
 * Which is why this is a dialog rather than a row button: the control sits beside "Read now" in the same table,
 * and one of the two is irreversible.
 */
export function RemoveSourceDialog({
  source,
  onOpenChange,
}: {
  source: MeasurementSource | null;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const remove = useDeleteSourceMutation();

  return (
    <Dialog.Root
      open={source !== null}
      onOpenChange={(next) => {
        if (!next) {
          remove.reset();
        }
        onOpenChange(next);
      }}
    >
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg duration-[var(--ds-duration-base)] rtl:translate-x-1/2"
        >
          <Dialog.Title className="text-content text-lg font-semibold">
            {t('tracking.remove.title', { name: source?.name ?? '' })}
          </Dialog.Title>
          <Dialog.Description className="text-content-secondary mt-1 text-sm">
            {t('tracking.remove.body')}
          </Dialog.Description>

          <ul className="text-content-secondary mt-4 flex list-disc flex-col gap-1.5 ps-5 text-sm">
            <li>{t('tracking.remove.counts')}</li>
            <li>{t('tracking.remove.mappings')}</li>
            <li>{t('tracking.remove.leads')}</li>
          </ul>

          {remove.isError ? (
            <Alert tone="danger" className="mt-4">
              {remove.error instanceof ApiError && remove.error.message !== ''
                ? remove.error.message
                : t('tracking.remove.failed')}
            </Alert>
          ) : null}

          <div className="mt-5 flex justify-end gap-2">
            <Dialog.Close asChild>
              <Button type="button" variant="secondary">
                {t('actions.cancel')}
              </Button>
            </Dialog.Close>
            <Button
              type="button"
              variant="danger"
              disabled={remove.isPending || source === null}
              onClick={() => {
                if (source !== null) {
                  remove.mutate(source.id, { onSuccess: () => onOpenChange(false) });
                }
              }}
            >
              {t('tracking.remove.confirm')}
            </Button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
