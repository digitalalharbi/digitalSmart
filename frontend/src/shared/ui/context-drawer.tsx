import { useEffect, useRef, type ReactNode } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';
import { Button } from '@/shared/ui/button';

/**
 * Detail without leaving the page.
 *
 * Opening a lead, a creative or a campaign from a list used to mean a route change, and coming back meant
 * losing the filters, the scroll position and the thread of what you were doing. A drawer keeps the list
 * behind it, so working through twenty rows is twenty glances rather than forty navigations.
 *
 * **It slides from the inline end**, not from the right: in Arabic the list is on the right and the detail
 * belongs on the left, and `start`/`end` get that for free where a hardcoded `right-0` would put the panel
 * on top of what it is meant to explain. Below `sm` it is a full-height sheet — a 28rem panel on a phone is
 * the whole screen with a decorative gap.
 *
 * The caller still owns the route when a detail deserves its own URL; this is for the case where it does not.
 */
export function ContextDrawer({
  open,
  onOpenChange,
  title,
  description,
  footer,
  children,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string | undefined;
  footer?: ReactNode | undefined;
  children: ReactNode;
}) {
  const { t } = useTranslation();
  /*
   * Where focus was when this opened.
   *
   * Radix restores focus to its own `Dialog.Trigger`, and this drawer deliberately has none: it is opened
   * from a table row, a card, a menu item — whatever the caller has. Without this, closing drops focus onto
   * `<body>`, and a keyboard user who opened the fourth row has to tab from the top of the page to reach the
   * fifth. WCAG 2.2 calls that a focus-order failure; it is also just miserable to use.
   */
  const opener = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (open) {
      opener.current =
        document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }
  }, [open]);

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          /*
           * Radix warns when a dialog has no description, and the usual workaround — rendering an empty
           * `Dialog.Description` — satisfies the warning by pointing `aria-describedby` at a blank element,
           * which is worse: a screen reader then announces nothing where it promised something. Saying there
           * is no description is the honest form of the same fix.
           */
          aria-describedby={description === undefined ? undefined : 'ds-drawer-description'}
          onCloseAutoFocus={(event) => {
            const origin = opener.current;

            if (origin !== null && origin.isConnected) {
              event.preventDefault();
              origin.focus();
            }
          }}
          className="bg-surface border-line-subtle animate-in fade-in fixed inset-y-0 end-0 z-50 flex w-full max-w-[28rem] flex-col border-s shadow-lg duration-[var(--ds-duration-base)]"
        >
          <div className="border-line-subtle flex items-start justify-between gap-4 border-b px-5 py-4">
            <div className="min-w-0">
              <Dialog.Title className="text-content truncate text-base font-semibold">
                {title}
              </Dialog.Title>
              {description === undefined ? null : (
                <Dialog.Description
                  id="ds-drawer-description"
                  className="text-content-secondary mt-1 text-sm"
                >
                  {description}
                </Dialog.Description>
              )}
            </div>
            <Dialog.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t('actions.close')}>
                <X />
              </Button>
            </Dialog.Close>
          </div>

          <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>

          {footer === undefined ? null : (
            <div className="border-line-subtle bg-surface-sunken border-t px-5 py-3">{footer}</div>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
