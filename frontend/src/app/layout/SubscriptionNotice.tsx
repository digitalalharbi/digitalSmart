import { useTranslation } from 'react-i18next';
import { useSession } from '@/features/auth';
import { localeTag } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';

/**
 * Says, on every screen, when the workspace is not simply active: a trial with its end date, a payment the account is waiting
 * on, a grace period, or a workspace that is paused or has ended. Told up front rather than discovered through a refused save.
 */
export function SubscriptionNotice() {
  const { t, i18n } = useTranslation();
  const { user } = useSession();
  const subscription = user?.subscription;

  if (subscription === undefined || subscription === null || subscription.status === 'active') {
    return null;
  }

  const date = (value: string | null): string =>
    value === null ? '' : new Date(value).toLocaleDateString(localeTag(i18n.language));
  const stopped = subscription.status === 'suspended' || subscription.status === 'cancelled';

  return (
    <Alert
      tone={stopped ? 'danger' : subscription.status === 'trial' ? 'info' : 'warning'}
      className="mb-4"
    >
      {t(`subscription.${subscription.status}`, {
        trialEnds: date(subscription.trial_ends_at),
        graceEnds: date(subscription.grace_ends_at),
      })}
      {stopped && subscription.reason !== null
        ? ` ${t('subscription.reason', { reason: subscription.reason })}`
        : null}
    </Alert>
  );
}
