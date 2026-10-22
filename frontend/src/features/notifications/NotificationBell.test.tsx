import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { NotificationBell } from './NotificationBell';
import type { NotificationFeed } from './types';

vi.mock('@/features/auth', () => ({
  useSession: () => ({
    user: { id: 'user-1', active_workspace: { id: 'workspace-1' } },
    isAuthenticated: true,
  }),
}));

vi.mock('@/shared/realtime/echo', () => ({
  isRealtimeConfigured: () => false,
  connectRealtime: () => Promise.resolve(null),
  disconnectRealtime: () => undefined,
}));

const state = vi.hoisted(() => ({ feed: null as NotificationFeed | null }));

vi.mock('./api', () => ({
  fetchNotifications: () => Promise.resolve(state.feed),
  fetchNotificationVocabulary: () => Promise.resolve({ kinds: [] }),
  markNotificationRead: () => Promise.resolve(state.feed),
  markAllNotificationsRead: () => Promise.resolve(state.feed),
}));

function feed(waiting: number, unread: number): NotificationFeed {
  return {
    notifications: [],
    digest: {
      total: unread,
      unread,
      waiting,
      unread_by_kind: {},
      unread_by_category: {} as never,
      unread_by_severity: {} as never,
    },
    is_live: false,
  };
}

beforeEach(() => {
  state.feed = feed(0, 0);
});

describe('the bell', function () {
  it('counts what is waiting, not what is unread', async () => {
    state.feed = feed(2, 7);

    await renderWithProviders(<NotificationBell />);

    /*
     * The whole argument for this badge. Seven unread measures how long somebody has been away; two waiting on
     * them is a reason to look. The accessible name says which number it is, so the badge is not the only way
     * to find out.
     */
    expect(
      await screen.findByRole('button', { name: 'Notifications. 2 things waiting on you.' }),
    ).toBeInTheDocument();
    expect(screen.getByText('2')).toBeInTheDocument();
    expect(screen.queryByText('7')).not.toBeInTheDocument();
  });

  it('shows a dot rather than a count for things that are merely unread', async () => {
    state.feed = feed(0, 3);

    await renderWithProviders(<NotificationBell />);

    /*
     * Nothing to do about them, so a number would invite a reader to clear a figure that is not a task.
     */
    expect(
      await screen.findByRole('button', { name: 'Notifications. 3 unread.' }),
    ).toBeInTheDocument();
    expect(screen.queryByText('3')).not.toBeInTheDocument();
  });

  it('shows no badge at all when nothing is waiting', async () => {
    await renderWithProviders(<NotificationBell />);

    // A dot that is always there is a dot nobody reads, and a zero in a badge is a number pretending to inform.
    expect(
      await screen.findByRole('button', { name: 'Notifications. Nothing waiting on you.' }),
    ).toBeInTheDocument();
  });
});
