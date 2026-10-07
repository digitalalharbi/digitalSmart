import { Bell } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { useSession } from '@/features/auth';
import { cn } from '@/shared/lib/cn';
import { formatNumber } from '@/shared/lib/format';
import { Button } from '@/shared/ui/button';
import { useNotificationRealtime, useNotificationsQuery } from './useNotifications';

/**
 * The bell in the top bar.
 *
 * **It carries `waiting`, not `unread`.** A badge showing how many things somebody has not clicked is a measure
 * of how long they have been away; a badge showing how many things are waiting on them is a reason to look. Being
 * told an answer is unread without being waiting, and that distinction is what keeps the number trustworthy.
 *
 * **No badge at all when nothing is waiting.** A dot that is always there is a dot nobody reads, and a zero in a
 * badge is a number pretending to be information.
 *
 * It also owns the realtime subscription, because the bell is the one thing present on every screen — so a
 * notification arriving while somebody is looking at the attribution page still moves the number.
 */
export function NotificationBell() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { user, isAuthenticated } = useSession();

  const feed = useNotificationsQuery(false, isAuthenticated);

  useNotificationRealtime(user?.active_workspace?.id ?? null, user?.id ?? null);

  const waiting = feed.data?.digest.waiting ?? 0;
  const unread = feed.data?.digest.unread ?? 0;

  return (
    <Button
      variant="ghost"
      size="icon"
      onClick={() => void navigate('/notifications')}
      aria-label={
        waiting > 0
          ? t('notifications.bell.waiting', { count: waiting })
          : unread > 0
            ? t('notifications.bell.unread', { count: unread })
            : t('notifications.bell.quiet')
      }
      title={t('nav.notifications')}
      className="relative"
    >
      <Bell />
      {waiting > 0 ? (
        <span
          aria-hidden
          className={cn(
            'bg-danger text-accent-contrast absolute end-1 top-1 min-w-4 rounded-full px-1',
            'text-[0.625rem] leading-4 font-semibold tabular-nums',
          )}
        >
          {formatNumber(waiting, i18n.language)}
        </span>
      ) : unread > 0 ? (
        /*
         * A dot rather than a count for things that are merely unread. There is nothing to do about them, so a
         * number would be inviting a reader to clear a figure that is not a task.
         */
        <span aria-hidden className="bg-accent absolute end-1.5 top-1.5 size-2 rounded-full" />
      ) : null}
    </Button>
  );
}
