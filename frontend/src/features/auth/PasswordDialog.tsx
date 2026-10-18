import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useChangePasswordMutation } from './api';
import { returnFocus } from '@/shared/ui/return-focus';

/** The server's minimum. Repeated here only as the hint a reader sees before they are refused. */
const MINIMUM_LENGTH = 12;

/**
 * Change your own password.
 *
 * **In the account menu rather than on a settings page**, because a password belongs to the person and not to a
 * workspace: a media buyer must be able to change theirs without holding `workspaces.update`, and a page behind
 * that permission would have put it out of reach of most of the people who need it.
 *
 * **The current password is asked for**, and the hint says why rather than leaving it as ceremony: a session can
 * be left open on a machine that is not yours, and without the check whoever found it could lock the owner out.
 *
 * **What else the change does is stated before it is made.** Every other session using the old password ends,
 * which is the entire point of changing a password somebody may know — and a reader who is not told will wonder
 * afterwards why their phone signed itself out.
 *
 * Nothing here reports strength. A meter is a model's opinion rendered as a fact, and this product does not do
 * that with figures a client sees or with a reader's own password.
 */
export function PasswordDialog({
  open,
  onOpenChange,
  isForced = false,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * True when the reader signed in with a password somebody else chose.
   *
   * The server refuses everything else until it is replaced, so the dialog cannot be dismissed and the way out
   * is signing out rather than cancelling. A cancel button here would close a dialog onto a product that
   * answers nothing.
   */
  isForced?: boolean;
}) {
  const { t } = useTranslation();
  const change = useChangePasswordMutation();

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [isDone, setIsDone] = useState(false);

  const close = (isOpen: boolean) => {
    if (isForced) {
      /* Nothing to go back to: every other request is refused until this is done. */
      return;
    }

    if (!isOpen) {
      /* Nothing typed is kept: a password left in a closed dialog is a password left on a screen. */
      setCurrent('');
      setNext('');
      setConfirmation('');
      setIsDone(false);
      change.reset();
    }

    onOpenChange(isOpen);
  };

  const mismatch = confirmation !== '' && next !== confirmation;
  const tooShort = next !== '' && next.length < MINIMUM_LENGTH;

  return (
    <Dialog.Root open={open} onOpenChange={close}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg duration-[var(--ds-duration-base)] rtl:translate-x-1/2"
        >
          <div className="mb-5 flex items-start justify-between gap-4">
            <div>
              <Dialog.Title className="text-content text-lg font-semibold">
                {t('account.password.title')}
              </Dialog.Title>
              <Dialog.Description className="text-content-secondary mt-1 text-sm">
                {t('account.password.description')}
              </Dialog.Description>
            </div>
            {/* No way out while the password is somebody else's: the product behind this answers nothing. */}
            {isForced ? null : (
              <Dialog.Close asChild>
                <Button variant="ghost" size="icon" aria-label={t('actions.close')}>
                  <X />
                </Button>
              </Dialog.Close>
            )}
          </div>

          {isDone ? (
            <div className="flex flex-col gap-4">
              <Alert tone="info">
                {isForced ? t('account.password.doneForced') : t('account.password.done')}
              </Alert>
              {isForced ? (
                /*
                 * A reload rather than a close, because the session's flag is what the router gates on and the
                 * cached copy of it is now wrong. Re-reading the session is the one thing that opens the
                 * product.
                 */
                <Button onClick={() => window.location.reload()}>
                  {t('account.password.continue')}
                </Button>
              ) : (
                <Dialog.Close asChild>
                  <Button>{t('actions.close')}</Button>
                </Dialog.Close>
              )}
            </div>
          ) : (
            <form
              className="flex flex-col gap-4"
              onSubmit={(event) => {
                event.preventDefault();

                if (mismatch || tooShort || current === '' || next === '') {
                  return;
                }

                change.mutate(
                  {
                    current_password: current,
                    password: next,
                    password_confirmation: confirmation,
                  },
                  {
                    onSuccess: () => {
                      setCurrent('');
                      setNext('');
                      setConfirmation('');
                      setIsDone(true);
                    },
                  },
                );
              }}
            >
              {/*
                Said before it happens. A reader who is not told will wonder afterwards why their phone signed
                itself out — and that is the behaviour they are asking for when they change a password.
              */}
              {/*
                A different sentence when the password was handed over: what matters then is not that other
                sessions end but that this one cannot do anything else until it is replaced.
              */}
              <Alert tone={isForced ? 'warning' : 'info'}>
                {isForced ? t('account.password.required') : t('account.password.consequence')}
              </Alert>

              {change.error === null ? null : (
                <Alert tone="danger">
                  {change.error instanceof ApiError && change.error.message !== ''
                    ? change.error.message
                    : t('account.password.failed')}
                </Alert>
              )}

              <Field
                label={t('account.password.current')}
                hint={t('account.password.currentHint')}
                required
              >
                {(props) => (
                  <Input
                    {...props}
                    type="password"
                    autoComplete="current-password"
                    value={current}
                    onChange={(event) => setCurrent(event.target.value)}
                    required
                  />
                )}
              </Field>

              <Field
                label={t('account.password.next')}
                hint={t('account.password.nextHint', { minimum: MINIMUM_LENGTH })}
                error={
                  tooShort ? t('account.password.tooShort', { minimum: MINIMUM_LENGTH }) : undefined
                }
                required
              >
                {(props) => (
                  <Input
                    {...props}
                    type="password"
                    autoComplete="new-password"
                    value={next}
                    onChange={(event) => setNext(event.target.value)}
                    required
                  />
                )}
              </Field>

              <Field
                label={t('account.password.confirm')}
                error={mismatch ? t('account.password.mismatch') : undefined}
                required
              >
                {(props) => (
                  <Input
                    {...props}
                    type="password"
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(event) => setConfirmation(event.target.value)}
                    required
                  />
                )}
              </Field>

              <div className="flex justify-end gap-2">
                {isForced ? null : (
                  <Dialog.Close asChild>
                    <Button type="button" variant="secondary">
                      {t('actions.cancel')}
                    </Button>
                  </Dialog.Close>
                )}
                <Button type="submit" disabled={change.isPending || mismatch || tooShort}>
                  {change.isPending ? t('actions.saving') : t('account.password.submit')}
                </Button>
              </div>
            </form>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
