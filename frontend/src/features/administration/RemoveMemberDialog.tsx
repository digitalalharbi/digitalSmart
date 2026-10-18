import * as Dialog from '@radix-ui/react-dialog';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Skeleton } from '@/shared/ui/skeleton';
import { useHeldWorkQuery, useRemoveMemberMutation } from './api';
import type { HeldWork, Member } from './types';
import { returnFocus } from '@/shared/ui/return-focus';

/** The four tables that carry an assignment, in the order the boards are read. */
const KINDS = ['tasks', 'leads', 'client_requests', 'creative_requests'] as const;

/**
 * Removing somebody, and what that does to the work in their name.
 *
 * **The consequence is shown before the decision, not after it.** Removal is irreversible and it releases
 * everything still open in that person's name — tasks, leads, client requests, creative briefs. "Remove" over a
 * name holding eleven open items and "remove" over a name holding none are different decisions, and the button
 * alone renders them identically. So the dialog asks the server what they hold and says it.
 *
 * **It does not offer to reassign.** Handing eleven items to one colleague in a dialog nobody can see the work
 * from would be a worse outcome than releasing it: the items come back as unassigned, which is the truth, and
 * every board filters for exactly that. The counts are reported afterwards so somebody knows what to pick up.
 *
 * **Their account and audit trail survive.** They lose this workspace, and finished work keeps their name,
 * because a task somebody completed is the record of who completed it.
 */
export function RemoveMemberDialog({
  member,
  onOpenChange,
}: {
  member: Member | null;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();

  const held = useHeldWorkQuery(member?.user_id ?? null);
  const remove = useRemoveMemberMutation();

  /* What came back from the removal, which is what the person who did it needs to read. */
  const released: HeldWork | undefined = remove.data;
  const isDone = remove.isSuccess;

  return (
    <Dialog.Root
      open={member !== null}
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
            {isDone
              ? t('administration.remove.doneTitle')
              : t('administration.remove.title', { name: member?.name ?? '' })}
          </Dialog.Title>
          <Dialog.Description className="text-content-secondary mt-1 text-sm">
            {isDone
              ? t('administration.remove.doneBody', { name: member?.name ?? '' })
              : t('administration.remove.body')}
          </Dialog.Description>

          <div className="mt-4 flex flex-col gap-4">
            {remove.error instanceof ApiError ? (
              <Alert tone="danger">{remove.error.message}</Alert>
            ) : null}

            {/*
              Before: what they hold. After: what was released. The same four counts either way, so the figure
              somebody read before deciding is the figure they can check afterwards.
            */}
            {isDone ? (
              <WorkList work={released} legend={t('administration.remove.releasedLegend')} />
            ) : held.isPending ? (
              <Skeleton className="h-16 w-full" />
            ) : held.isError ? (
              /*
               * The count could not be read, so the dialog does not invent one. Removal is still offered —
               * refusing it because a figure is unavailable would be the wrong trade — but it says plainly
               * that it cannot show what will be released.
               */
              <Alert tone="warning">{t('administration.remove.unknownHeld')}</Alert>
            ) : held.data && held.data.total > 0 ? (
              <WorkList work={held.data} legend={t('administration.remove.heldLegend')} />
            ) : (
              <p className="text-content-secondary text-sm">
                {t('administration.remove.holdsNothing')}
              </p>
            )}

            <div className="flex justify-end gap-2">
              {isDone ? (
                <Dialog.Close asChild>
                  <Button type="button">{t('actions.close')}</Button>
                </Dialog.Close>
              ) : (
                <>
                  <Dialog.Close asChild>
                    <Button type="button" variant="secondary">
                      {t('actions.cancel')}
                    </Button>
                  </Dialog.Close>
                  <Button
                    type="button"
                    variant="danger"
                    disabled={remove.isPending || member === null}
                    onClick={() => {
                      if (member !== null) {
                        remove.mutate(member.user_id);
                      }
                    }}
                  >
                    {t('administration.remove.confirm')}
                  </Button>
                </>
              )}
            </div>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

/** The four counts, named, with the kinds holding nothing left out rather than listed as zeroes. */
function WorkList({ work, legend }: { work: HeldWork | undefined; legend: string }) {
  const { t } = useTranslation();
  const present = KINDS.filter((kind) => (work?.[kind] ?? 0) > 0);

  if (work === undefined || work.total === 0) {
    return (
      <p className="text-content-secondary text-sm">{t('administration.remove.nothingReleased')}</p>
    );
  }

  return (
    <div className="border-line-subtle rounded-lg border p-3">
      <p className="text-content-secondary text-sm">{legend}</p>
      <ul className="mt-2 flex flex-col gap-1">
        {present.map((kind) => (
          <li key={kind} className="text-content flex justify-between gap-4 text-sm">
            <span>{t(`administration.remove.kinds.${kind}`)}</span>
            <span className="ds-numeric font-medium">{work[kind]}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
