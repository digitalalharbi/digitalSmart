import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { connectRealtime, isRealtimeConfigured } from '@/shared/realtime/echo';

/** The three boards the server publishes a channel for. */
export type BoardName = 'tasks' | 'approvals' | 'client_requests';

const KEYS: Record<BoardName, () => readonly unknown[]> = {
  tasks: () => queryKeys.tasks.all(),
  approvals: () => queryKeys.approvals.all(),
  client_requests: () => queryKeys.clientRequests.all(),
};

/**
 * Keep an open board current when somebody else moves something.
 *
 * **The event is a nudge and the refetch is the truth.** The payload carries the board's name and a timestamp and
 * nothing else — no row, no title, no state — so this hook's only possible response is to invalidate and read the
 * board again over the authenticated endpoint, where project visibility decides which rows this person gets. A
 * payload carrying the row would have to answer that question a second time, and the second answer is the one
 * that eventually differs.
 *
 * It returns whether this session is actually being pushed to, and the surfaces **say so**. A page that implied
 * it was live while it was not is a page where somebody waits for an update that never comes — the same reason
 * the notification feed publishes `is_live` rather than assuming it.
 *
 * Does nothing at all when realtime is not configured, which is the normal state of a checkout.
 */
export function useBoardRealtime(board: BoardName, workspaceId: string | null): boolean {
  const client = useQueryClient();
  const [isLive, setIsLive] = useState(false);

  useEffect(() => {
    if (workspaceId === null || !isRealtimeConfigured()) {
      return;
    }

    const channel = `workspace.${workspaceId}.board.${board}`;
    let cancelled = false;
    let subscribed: Awaited<ReturnType<typeof connectRealtime>> = null;

    void connectRealtime().then((echo) => {
      if (echo === null || cancelled) {
        return;
      }

      subscribed = echo;

      echo.private(channel).listen('.board.changed', () => {
        void client.invalidateQueries({ queryKey: KEYS[board]() });
      });

      /*
       * Set once the subscription exists rather than when the hook runs. "Live" has to mean a socket this page
       * is actually on, not a configuration value that was present at boot.
       */
      setIsLive(true);
    });

    return () => {
      cancelled = true;
      setIsLive(false);
      // Left connected: the socket is shared per tab, and only a sign-out closes it.
      subscribed?.leave(channel);
    };
  }, [board, workspaceId, client]);

  return isLive;
}
