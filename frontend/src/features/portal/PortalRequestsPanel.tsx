import { useTranslation } from 'react-i18next';
import { RequestsPage } from '@/features/requests';
import { Alert } from '@/shared/ui/alert';

/**
 * The thread the client writes in.
 *
 * `RequestsPage` is reused unchanged. It already renders the client-facing side of the intake queue when the
 * server reports the reader is not agency staff — the internal notes are excluded from that read in SQL rather
 * than hidden by the page — and a second implementation of a client's own correspondence would be a second place
 * for that exclusion to be got wrong.
 *
 * The sentence above it is the point of this panel: this surface is not a setting, and an agency cannot switch off
 * a client's ability to ask for something or to read what they were told.
 */
export function PortalRequestsPanel() {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col gap-4">
      <Alert tone="info">{t('myPortal.requests.always')}</Alert>
      <RequestsPage />
    </div>
  );
}
