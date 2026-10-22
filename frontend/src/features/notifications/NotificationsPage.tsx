import { BellOff, RadioTower, WifiOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { isRealtimeConfigured } from '@/shared/realtime/echo';
import { DigestSettings } from './DigestSettings';
import { NotificationCard } from './NotificationCard';
import type { NotificationCategoryValue } from './types';
import {
  useDismissMutation,
  useMarkAllReadMutation,
  useMarkClickedMutation,
  useMarkReadMutation,
  useNotificationRealtime,
  useNotificationVocabularyQuery,
  useNotificationsQuery,
} from './useNotifications';

const TABS: NotificationCategoryValue[] = [
  'performance',
  'budget',
  'creative',
  'tracking',
  'approvals',
  'reports',
  'work',
];

/**
 * The notification centre.
 *
 * Two things on this page are deliberate.
 *
 * **It leads with what is waiting, not with what is unread.** "Twelve unread" measures how long somebody has
 * been away; "two waiting on you" is a to-do list. Being told an answer is not a task, so it counts as unread
 * and not as waiting.
 *
 * **It says whether it is being pushed to or is polling**, and "live" requires **both** sides. The server
 * reports whether it can push; this page also knows whether its own browser has a key to subscribe with, and
 * either being absent means polling. Taking the server's word alone was the actual bug here: a checkout with a
 * broadcast connection configured and no frontend key told every reader the page was live while nothing was
 * subscribed. With no WebSocket server, notifications still arrive on the next read — later, never missing — and
 * a page that implies it is live while polling is one where somebody waits for an update that never animates,
 * and then stops believing the badge.
 */
export function NotificationsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { user } = useSession();
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [tab, setTab] = useState<NotificationCategoryValue | null>(null);

  const feed = useNotificationsQuery(unreadOnly, true, tab);
  const markRead = useMarkReadMutation();
  const markClicked = useMarkClickedMutation();
  const dismiss = useDismissMutation();
  const markAll = useMarkAllReadMutation();

  useNotificationRealtime(user?.active_workspace?.id ?? null, user?.id ?? null);

  const error = useMemo(() => {
    for (const mutation of [markRead, markAll, markClicked, dismiss]) {
      if (mutation.error instanceof ApiError && mutation.error.message !== '') {
        return mutation.error.message;
      }
    }

    return null;
  }, [markRead, markAll, markClicked, dismiss]);

  const digest = feed.data?.digest;
  /* Both sides. See the note above: either one being off means this page is polling. */
  const isLive = (feed.data?.is_live ?? false) && isRealtimeConfigured();

  return (
    <div className="ds-page">
      <PageHeader
        title={t('notifications.title')}
        description={t('notifications.subtitle')}
        filters={
          <div className="flex flex-wrap items-end justify-between gap-4">
            {/* A tab is a stored column on the server, so its count and its list cannot disagree. */}
            <ChoiceGroup label={t('notifications.tabs.label')}>
              <div className="flex flex-wrap gap-2">
                <Choice isSelected={tab === null} onSelect={() => setTab(null)}>
                  {t('notifications.tabs.all')}
                </Choice>
                {TABS.map((category) => {
                  const unread = digest?.unread_by_category?.[category] ?? 0;

                  return (
                    <Choice
                      key={category}
                      isSelected={tab === category}
                      onSelect={() => setTab(category)}
                    >
                      {t(`notifications.tabs.${category}`)}
                      {unread > 0 ? ` · ${formatNumber(unread, locale)}` : ''}
                    </Choice>
                  );
                })}
              </div>
            </ChoiceGroup>
            <ChoiceGroup label={t('notifications.scope.show')}>
              <div className="flex flex-wrap gap-2">
                <Choice isSelected={!unreadOnly} onSelect={() => setUnreadOnly(false)}>
                  {t('notifications.scope.everything')}
                </Choice>
                <Choice isSelected={unreadOnly} onSelect={() => setUnreadOnly(true)}>
                  {t('notifications.scope.unread')}
                </Choice>
              </div>
            </ChoiceGroup>

            {digest === undefined || digest.unread === 0 ? null : (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => markAll.mutate(undefined)}
                disabled={markAll.isPending}
              >
                {t('notifications.markAll')}
              </Button>
            )}
          </div>
        }
      />

      {error === null ? null : <Alert tone="danger">{error}</Alert>}
      {feed.isError ? <Alert tone="danger">{t('notifications.loadFailed')}</Alert> : null}

      {feed.isPending || digest === undefined || feed.data === undefined ? (
        <Skeleton className="h-32 w-full" />
      ) : (
        <>
          <section
            aria-label={t('notifications.counts')}
            className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
          >
            {[
              /*
               * Waiting first, and it is the figure the bell in the shell carries too. The other two are
               * context: how much is new, and how much there has ever been.
               */
              { key: 'waiting', value: digest.waiting, tone: 'warning' as const },
              {
                key: 'critical',
                value: digest.unread_by_severity?.critical ?? 0,
                tone: 'neutral' as const,
              },
              { key: 'unread', value: digest.unread, tone: 'neutral' as const },
              { key: 'total', value: digest.total, tone: 'neutral' as const },
            ].map((figure) => (
              <Card key={figure.key}>
                <CardContent className="flex flex-col gap-1 pt-5">
                  <span className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                    {t(`notifications.count.${figure.key}`)}
                  </span>
                  <span className="text-content text-2xl font-semibold">
                    {formatNumber(figure.value, locale)}
                  </span>
                  {figure.value > 0 && figure.tone !== 'neutral' ? (
                    <Badge tone={figure.tone}>{t('notifications.count.waitingNote')}</Badge>
                  ) : null}
                </CardContent>
              </Card>
            ))}
          </section>

          {/*
            Said plainly, either way. Realtime being off is not a failure — notifications are written to the
            database and arrive on the next read — but a reader who thinks the page is live and sees nothing
            move will conclude the product is broken.
          */}
          <p className="text-content-tertiary flex items-center gap-1.5 text-sm">
            {isLive ? (
              <>
                <RadioTower aria-hidden className="size-3.5" />
                {t('notifications.live')}
              </>
            ) : (
              <>
                <WifiOff aria-hidden className="size-3.5" />
                {t('notifications.polling')}
              </>
            )}
          </p>

          {feed.data.notifications.length === 0 ? (
            <EmptyState
              title={t(
                tab !== null
                  ? 'notifications.empty.tabTitle'
                  : unreadOnly
                    ? 'notifications.empty.unreadTitle'
                    : 'notifications.empty.title',
              )}
              description={t(
                tab !== null
                  ? 'notifications.empty.tabBody'
                  : unreadOnly
                    ? 'notifications.empty.unreadBody'
                    : 'notifications.empty.body',
              )}
            />
          ) : (
            <ul aria-label={t('notifications.list')} className="flex flex-col gap-2">
              {feed.data.notifications.map((item) => (
                <li key={item.id}>
                  <NotificationCard
                    item={item}
                    /*
                     * Opening the thing is reading the notification, and the server records the click too.
                     * Leaving it unread after somebody has acted on it is how a badge comes to show a number
                     * nobody can clear.
                     */
                    onOpen={() => markClicked.mutate(item.id)}
                    onRead={() => markRead.mutate(item.id)}
                    onDismiss={() => dismiss.mutate(item.id)}
                  />
                </li>
              ))}
            </ul>
          )}

          {/*
            What can arrive, read from the server rather than written here.

            This replaced a sentence naming "four things", while the server announced ten — including lead
            assignments, which this feature's own type union had never heard of. A hand-kept list of what a
            product can tell you is a list that drifts, and the drift is invisible precisely because it reads
            as an explanation rather than as data.
          */}
          <DigestSettings />

          <KindDisclosure isEmpty={digest.total === 0} />
        </>
      )}
    </div>
  );
}

/**
 * What puts something here, and who gets told.
 *
 * Shown always, and expanded by default when the feed is empty: an empty notification centre is usually a
 * quiet week rather than a broken feature, and that is exactly when a reader needs the list.
 *
 * **No permission slugs.** The vocabulary carries the permission each kind requires, and it is operator
 * detail: a reader needs to know that a kind reaches only the people who can already see what it is about,
 * not that the gate is spelled `client_requests.triage`. Saying the rule in a sentence is honest; printing
 * the slug to a client viewer is a diagnostic leak.
 */
function KindDisclosure({ isEmpty }: { isEmpty: boolean }) {
  const { t } = useTranslation();
  const vocabulary = useNotificationVocabularyQuery();

  const kinds = vocabulary.data?.kinds ?? [];

  if (kinds.length === 0) {
    /* No invented list. The feed above is the product; this panel is an explanation of it. */
    return null;
  }

  return (
    <details open={isEmpty} className="border-line-subtle rounded-2xl border p-4">
      <summary className="text-content cursor-pointer text-sm font-medium">
        <span className="inline-flex items-center gap-1.5">
          <BellOff aria-hidden className="size-3.5" />
          {t('notifications.vocabulary.title')}
        </span>
      </summary>

      <p className="text-content-secondary mt-3 text-sm">{t('notifications.vocabulary.rule')}</p>

      <ul className="mt-3 flex flex-col gap-1.5">
        {kinds.map((kind) => (
          <li key={kind.kind} className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-content">{kind.label}</span>
            {kind.needs_action ? (
              <Badge tone="warning">{t('notifications.vocabulary.needsAction')}</Badge>
            ) : null}
          </li>
        ))}
      </ul>
    </details>
  );
}
