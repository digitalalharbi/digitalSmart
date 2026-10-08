import { Bell } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useMarkAllReadMutation, useNotificationsQuery } from '@/features/notifications';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent } from '@/shared/ui/card';

/**
 * What changed since the client last looked.
 *
 * The portal has no bell and should not have one — it is not the agency's product. But a client who is told
 * nothing has to open the portal and compare it against their memory, and this product now *does* tell them: a
 * request that moves, a question the agency needs answered, a reply. A notification with nowhere to be read is a
 * notification that was not sent.
 *
 * So this is the quietest possible surface for it. Unread only, no history, no counts of things already seen —
 * the agency's notification centre keeps a record, and a client does not need one. It is absent entirely when
 * there is nothing to say, because a permanently visible panel reading "nothing new" is furniture.
 *
 * The sentences are the server's own, written once when the thing happened and never re-rendered. A portal that
 * re-composed them from the request's current state would say "your request was answered" about a request that
 * has since moved twice.
 */
export function PortalUpdates() {
  const { t } = useTranslation();

  const feed = useNotificationsQuery(true);
  const markAllRead = useMarkAllReadMutation();

  const items = feed.data?.notifications ?? [];

  if (items.length === 0) {
    return null;
  }

  const waiting = items.filter((item) => item.needs_action);

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-center gap-2">
            <Bell aria-hidden className="text-content-secondary size-4" />
            <h2 className="text-content text-sm font-semibold">{t('myPortal.updates.title')}</h2>
          </div>

          <Button
            variant="ghost"
            size="sm"
            onClick={() => markAllRead.mutate()}
            disabled={markAllRead.isPending}
          >
            {t('myPortal.updates.markRead')}
          </Button>
        </div>

        {/*
          The one figure worth leading with, and only when it is not zero. "Three updates" measures how long
          somebody has been away; "one waiting on you" is a thing to do.
        */}
        {waiting.length === 0 ? null : (
          <Alert tone="warning">{t('myPortal.updates.waiting', { count: waiting.length })}</Alert>
        )}

        <ul className="flex flex-col gap-2">
          {items.map((item) => (
            <li
              key={item.id}
              className="border-line-subtle flex flex-col gap-0.5 border-b pb-2 last:border-0 last:pb-0"
            >
              <p className="text-content text-sm font-medium">{item.title}</p>
              <p className="text-content-secondary text-xs">
                {/* The kind's own label, from the server, beside whatever the announcement carried. */}
                {item.kind_label}
                {item.body === null ? '' : ` · ${item.body}`}
              </p>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
