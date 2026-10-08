import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import { useTranslation } from 'react-i18next';
import { useSession } from '@/features/auth';
import { Skeleton } from '@/shared/ui/skeleton';
import { LoadingRegion } from '@/shared/ui/loading-region';

/**
 * Gate for authenticated areas.
 *
 * While the session is still being resolved the app renders a skeleton rather
 * than flashing the login page at someone who is already signed in.
 */
export function RequireSession({ children }: { children: ReactNode }) {
  const { isLoading, isAuthenticated } = useSession();
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

  return <>{children}</>;
}
