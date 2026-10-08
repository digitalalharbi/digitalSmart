import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import { useTranslation } from 'react-i18next';
import { PasswordDialog, useSession } from '@/features/auth';
import { Skeleton } from '@/shared/ui/skeleton';
import { LoadingRegion } from '@/shared/ui/loading-region';

/**
 * Gate for authenticated areas.
 *
 * While the session is still being resolved the app renders a skeleton rather
 * than flashing the login page at someone who is already signed in.
 */
export function RequireSession({ children }: { children: ReactNode }) {
  const { isLoading, isAuthenticated, user } = useSession();
  const location = useLocation();
  const { t } = useTranslation();

  if (isLoading) {
    return (
      <LoadingRegion label={t('auth.checkingSession')} className="bg-canvas min-h-dvh p-6">
        <div className="mx-auto flex max-w-5xl flex-col gap-4">
          <Skeleton className="h-10 w-48" />
          <Skeleton className="h-64" />
        </div>
      </LoadingRegion>
    );
  }

  if (!isAuthenticated) {
    // Remember where they were headed so sign-in can return them there.
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  /*
   * A password somebody else chose buys exactly one thing: the chance to choose a real one.
   *
   * The server refuses every other request until it is replaced, so the dialog is not dismissible and the
   * application behind it is not rendered — anything else would be a screen full of refusals with no way out of
   * it. This is the one modal in the product with no cancel, and that is the honest shape: the alternative on
   * offer is signing out, which the dialog's own copy points at.
   */
  if (user?.must_choose_password === true) {
    return (
      <div className="bg-canvas min-h-dvh">
        <PasswordDialog open onOpenChange={() => undefined} isForced />
      </div>
    );
  }

  return <>{children}</>;
}
