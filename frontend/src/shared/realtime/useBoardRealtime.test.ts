import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import type * as ReactQueryModule from '@tanstack/react-query';

type ReactQuery = typeof ReactQueryModule;

type Listeners = Record<string, (() => void) | undefined>;

const state = vi.hoisted((): { configured: boolean; listeners: Listeners; left: string[] } => ({
  configured: true,
  listeners: {},
  left: [],
}));

vi.mock('@/shared/realtime/echo', () => ({
  isRealtimeConfigured: () => state.configured,
  connectRealtime: () =>
    Promise.resolve({
      private: (channel: string) => ({
        listen: (event: string, handler: () => void) => {
          state.listeners[`${channel}:${event}`] = handler;
        },
      }),
      leave: (channel: string) => {
        state.left.push(channel);
      },
    }),
}));

const client = new QueryClient();
const invalidated: unknown[][] = [];

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<ReactQuery>();

  return {
    ...actual,
    useQueryClient: () => ({
      invalidateQueries: (input: { queryKey: unknown[] }) => {
        invalidated.push(input.queryKey);
      },
    }),
  };
});

beforeEach(() => {
  state.configured = true;
  state.listeners = {};
  state.left = [];
  invalidated.length = 0;
  client.clear();
});

describe('a board that is being pushed to', function () {
  it('subscribes to the board channel and refetches on a nudge', async () => {
    const { renderHook, waitFor } = await import('@testing-library/react');
    const { useBoardRealtime } = await import('./useBoardRealtime');

    const { result } = renderHook(() => useBoardRealtime('tasks', 'workspace-1'));

    await waitFor(() => {
      expect(result.current).toBe(true);
    });

    /*
     * "Live" means a socket this page is actually on, not a configuration value that was present at boot. The
     * flag is set once the subscription exists.
     */
    const key = 'workspace.workspace-1.board.tasks:.board.changed';
    expect(state.listeners[key]).toBeDefined();

    state.listeners[key]?.();

    /*
     * Invalidate, never insert. The payload carries the board's name and a timestamp — no row, no state — so the
     * only possible response is to read the board again over the authenticated endpoint, where project
     * visibility decides which rows this person gets.
     */
    expect(invalidated).toEqual([['tasks']]);
  });

  it('does nothing at all when realtime is not configured', async () => {
    state.configured = false;

    const { renderHook } = await import('@testing-library/react');
    const { useBoardRealtime } = await import('./useBoardRealtime');

    const { result } = renderHook(() => useBoardRealtime('approvals', 'workspace-1'));

    // The normal state of a checkout. Not an error, and not a page that claims to be live.
    expect(result.current).toBe(false);
    expect(state.listeners).toEqual({});
  });

  it('does nothing without a workspace', async () => {
    const { renderHook } = await import('@testing-library/react');
    const { useBoardRealtime } = await import('./useBoardRealtime');

    const { result } = renderHook(() => useBoardRealtime('client_requests', null));

    expect(result.current).toBe(false);
    expect(state.listeners).toEqual({});
  });

  it('leaves the channel when the board is left', async () => {
    const { renderHook, waitFor } = await import('@testing-library/react');
    const { useBoardRealtime } = await import('./useBoardRealtime');

    const { unmount, result } = renderHook(() =>
      useBoardRealtime('client_requests', 'workspace-1'),
    );

    await waitFor(() => {
      expect(result.current).toBe(true);
    });

    unmount();

    /*
     * The channel is left; the socket is not closed — it is shared per tab, and only a sign-out closes it.
     *
     * Asserted by membership rather than by exact equality: StrictMode mounts, unmounts and mounts again, so the
     * cleanup legitimately runs more than once. That the cleanup is idempotent is the property worth having, and
     * pinning a count would be pinning React's development behaviour instead.
     */
    expect(state.left).toContain('workspace.workspace-1.board.client_requests');
  });
});
