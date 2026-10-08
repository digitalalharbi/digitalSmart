import { useTranslation } from 'react-i18next';
import { IssueList } from '@/features/tracking';
import { Alert } from '@/shared/ui/alert';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { usePortalTrackingQuery } from './usePortal';

/**
 * Whether measurement on the client's own site is working.
 *
 * The one surface here that reports a **problem** rather than a result, and that is why it can be exposed at all:
 * the findings are facts about the client's own property. A client who cannot see that their purchase event
 * stopped firing three weeks ago is a client who will later be shown a conversion figure nobody can explain.
 *
 * `IssueList` is the agency's own component, with the evidence behind each finding. Showing the client a shorter
 * version would mean two diagnoses of one site, and the one they read would be the one nobody maintained.
 */
export function PortalTrackingPanel({ clientId }: { clientId: string }) {
  const { t } = useTranslation();
  const tracking = usePortalTrackingQuery(clientId);

  if (tracking.isPending) {
    return <Skeleton className="h-48 w-full" />;
  }

  const health = tracking.data;

  if (health === undefined) {
    return <Alert tone="danger">{t('myPortal.loadFailed')}</Alert>;
  }

  if (health.sources.length === 0) {
    return (
      <EmptyState
        title={t('myPortal.tracking.noSourcesTitle')}
        description={t('myPortal.tracking.noSourcesBody')}
      />
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <p className="text-content-secondary text-sm">
        {t('myPortal.tracking.window', { days: health.window_days })}
      </p>

      <IssueList issues={health.issues} />
    </div>
  );
}
