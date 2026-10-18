import * as Dialog from '@radix-ui/react-dialog';
import { FlaskConical, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useSession } from '@/features/auth';
import { Button } from '@/shared/ui/button';
import { returnFocus } from '@/shared/ui/return-focus';

/** Per workspace: shortening the notice in one sandbox says nothing about another. */
const keyFor = (workspaceId: string): string => `digitalsmart.sandbox-notice.${workspaceId}`;

/** Where the tour goes, in the order a morning goes. Each is a real page. */
const STEPS = [
  { key: 'overview', to: '/' },
  { key: 'campaigns', to: '/campaigns' },
  { key: 'project', to: '/projects' },
  { key: 'budget', to: '/budget-control' },
  { key: 'creative', to: '/creative-hub' },
  { key: 'reports', to: '/reports' },
] as const;

function readDismissed(workspaceId: string): boolean {
  try {
    return localStorage.getItem(keyFor(workspaceId)) === 'dismissed';
  } catch {
    return false;
  }
}

/**
 * Says, on every screen, that this is the sandbox.
 *
 * A demo agency full of believable figures is exactly the thing somebody could mistake for a live account, so the
 * label is not a setting: the full notice can be dismissed, the one-line reminder cannot. It appears only in a
 * workspace whose own name says it is a demonstration — nothing about a real workspace changes.
 *
 * The tour is six steps, each a real page, and is optional: it is offered, never forced.
 */
export function SandboxNotice() {
  const { t } = useTranslation();
  const session = useSession();
  const workspaceId = session.user?.active_workspace?.id ?? '';
  const [dismissed, setDismissed] = useState(() => readDismissed(workspaceId));
  const [tour, setTour] = useState<number | null>(null);

  const slug = session.user?.active_workspace?.slug ?? '';

  if (!slug.startsWith('demo-')) {
    return null;
  }

  const dismiss = () => {
    setDismissed(true);

    try {
      localStorage.setItem(keyFor(workspaceId), 'dismissed');
    } catch {
      /* A browser that will not remember it will show it again, which is the safe direction to fail. */
    }
  };

  const step = tour === null ? null : STEPS[tour];

  return (
    <>
      <div
        role="note"
        aria-label={t('sandbox.label')}
        className="bg-warning-surface/70 border-line-subtle text-content-secondary mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border px-3 py-2 text-xs"
      >
        <FlaskConical aria-hidden className="text-warning size-4 shrink-0" />
        <span className="min-w-0 flex-1">
          <strong className="text-content font-semibold">{t('sandbox.short')}</strong>
          {dismissed ? null : ` — ${t('sandbox.long')}`}
        </span>
        <Button variant="ghost" size="sm" onClick={() => setTour(0)}>
          {t('sandbox.tour')}
        </Button>
        {dismissed ? null : (
          <Button variant="ghost" size="icon" aria-label={t('sandbox.dismiss')} onClick={dismiss}>
            <X />
          </Button>
        )}
      </div>

      <Dialog.Root open={tour !== null} onOpenChange={(open) => (open ? undefined : setTour(null))}>
        <Dialog.Portal>
          <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40" />
          <Dialog.Content
            onCloseAutoFocus={returnFocus}
            className="bg-surface fixed start-1/2 top-1/2 z-50 flex w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 flex-col gap-4 rounded-xl p-6 shadow-lg rtl:translate-x-1/2"
          >
            <div>
              <p className="text-content-tertiary text-xs tabular-nums">
                {tour === null ? '' : `${String(tour + 1)} / ${String(STEPS.length)}`}
              </p>
              <Dialog.Title className="text-content mt-1 text-lg font-semibold">
                {step === undefined || step === null ? '' : t(`sandbox.step.${step.key}.title`)}
              </Dialog.Title>
              <Dialog.Description className="text-content-secondary mt-1 text-sm">
                {step === undefined || step === null ? '' : t(`sandbox.step.${step.key}.body`)}
              </Dialog.Description>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Button
                variant="ghost"
                size="sm"
                disabled={tour === null || tour === 0}
                onClick={() => setTour((value) => Math.max(0, (value ?? 0) - 1))}
              >
                {t('presentations.previous')}
              </Button>
              {step === undefined || step === null ? null : (
                <Link
                  to={step.to}
                  onClick={() => setTour(null)}
                  className="text-content-brand text-sm font-medium hover:underline"
                >
                  {t('sandbox.go')}
                </Link>
              )}
              {tour !== null && tour >= STEPS.length - 1 ? (
                <Button size="sm" onClick={() => setTour(null)}>
                  {t('sandbox.done')}
                </Button>
              ) : (
                <Button size="sm" onClick={() => setTour((value) => (value ?? 0) + 1)}>
                  {t('presentations.next')}
                </Button>
              )}
            </div>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
    </>
  );
}
