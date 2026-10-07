import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { connectRealtime, isRealtimeConfigured } from '@/shared/realtime/echo';
import {
  fetchNotificationVocabulary,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from './api';

/**
 * One person's notifications.
 *
 * `refetchInterval` is set only when realtime is **not** configured. A page being pushed to has no reason to
 * poll, and a page that polls while claiming to be live is the version where somebody notices the delay and
 * stops trusting the badge. Sixty seconds when polling: long enough not to be chatty, short enough that a bell
 * is not an hour stale.
 */
export function useNotificationsQuery(unreadOnly = false, enabled = true) {
  return useQuery({
    queryKey: queryKeys.notifications.feed(unreadOnly),
    queryFn: () => fetchNotifications(unreadOnly),
    enabled,
    staleTime: 15_000,
    ...(isRealtimeConfigured() ? {} : { refetchInterval: 60_000 }),
  });
}

export function useNotificationVocabularyQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.notifications.vocabulary(),
    queryFn: fetchNotificationVocabulary,
    enabled,
    staleTime: 10 * 60 * 1_000,
  });
}

/**
 * Subscribe to this person's channel, and refetch when something arrives.
 *
 * The socket payload is a **signal**, not the notification: it says that something arrived and what sort, and
 * this hook then reads the feed over the authenticated endpoint. One extra request buys a property worth having
 * — the contents of a notification never travel over a socket, so there is no payload for an unconsidered field
 * to be added to, and the shaping of a notification for a reader lives in exactly one place.
 *
 * Does nothing at all when realtime is not configured, which is the normal state of a checkout.
 */
export function useNotificationRealtime(workspaceId: string | null, userId: string | null) {
  const client = useQueryClient();

  useEffect(() => {
    if (workspaceId === null || userId === null || !isRealtimeConfigured()) {
      return;
    }

    const channel = `workspace.${workspaceId}.user.${userId}`;
    let cancelled = false;
    let subscribed: Awaited<ReturnType<typeof connectRealtime>> = null;

    void connectRealtime().then((echo) => {
      if (echo === null || cancelled) {
        return;
      }

      subscribed = echo;

      echo.private(channel).listen('.notification.announced', () => {
        /*
         * Invalidate rather than insert. The signal carries no title and no subject, and the counts are
         * aggregated server-side over kinds this hook does not want a second opinion on.
         */
        void client.invalidateQueries({ queryKey: queryKeys.notifications.all() });
      });
    });

    return () => {
      cancelled = true;
      // Left connected: the socket is shared per tab, and only a sign-out closes it.
      subscribed?.leave(channel);
    };
  }, [workspaceId, userId, client]);
}

function useNotificationMutation<TInput, TResult>(mutationFn: (input: TInput) => Promise<TResult>) {
  const client = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.notifications.all() });
    },
  });
}

export function useMarkReadMutation() {
  return useNotificationMutation(markNotificationRead);
}

export function useMarkAllReadMutation() {
  return useNotificationMutation(() => markAllNotificationsRead());
}
