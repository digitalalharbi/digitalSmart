import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { NotificationsPage } from './NotificationsPage';
import type { NotificationFeed, NotificationItem, NotificationKindInfo } from './types';

vi.mock('@/features/auth', () => ({
  useSession: () => ({
    user: { id: 'user-1', active_workspace: { id: 'workspace-1' } },
    isAuthenticated: true,
  }),
}));

/*
 * The browser's half of "live". Mocked rather than exercised: a WebSocket in a unit test tests the mock.
 *
 * It is a variable because **"live" requires both sides** — a server that can push and a browser with a key to
 * subscribe with — and the interesting case is the mismatch, where the server says it can push and this session
 * cannot subscribe. That mismatch was a real defect, so it has a test of its own below.
 */
const realtime = vi.hoisted(() => ({ configured: false }));

vi.mock('@/shared/realtime/echo', () => ({
  isRealtimeConfigured: () => realtime.configured,
  connectRealtime: () => Promise.resolve(null),
  disconnectRealtime: () => undefined,
}));

const state = vi.hoisted(() => ({
  feed: null as NotificationFeed | null,
  kinds: [] as NotificationKindInfo[],
  marked: null as string | null,
  clearedAll: false,
  lastUnreadOnly: null as boolean | null,
}));

vi.mock('./api', () => ({
  fetchNotifications: (unreadOnly: boolean) => {
    state.lastUnreadOnly = unreadOnly;

    return Promise.resolve(state.feed);
  },
  fetchNotificationVocabulary: () => Promise.resolve({ kinds: state.kinds }),
  markNotificationRead: (id: string) => {
    state.marked = id;

    return Promise.resolve(state.feed);
  },
  markAllNotificationsRead: () => {
    state.clearedAll = true;

    return Promise.resolve(state.feed);
  },
}));

function item(overrides: Partial<NotificationItem> = {}): NotificationItem {
  return {
    id: 'notification-1',
    kind: 'approval_requested',
    kind_label: 'A change is waiting for your decision',
    needs_action: true,
    title: 'Raise the Ramadan daily budget',
    body: 'Change spend — moves money.',
    subject: {
      type: 'approval',
      type_label: 'Approval request',
      id: 'approval-1',
      label: 'Raise the Ramadan daily budget',
      exists: true,
      path: '/approvals',
    },
    actor_name: 'Reem',
    is_unread: true,
    is_waiting: true,
    read_at: null,
    created_at: '2026-10-07T09:00:00+00:00',
    ...overrides,
  };
}

function feed(overrides: Partial<NotificationFeed> = {}): NotificationFeed {
  return {
    notifications: [item()],
    digest: {
      total: 3,
      unread: 2,
      waiting: 1,
      unread_by_kind: { approval_requested: 1, approval_decided: 1 },
    },
    is_live: false,
    ...overrides,
  };
}

beforeEach(() => {
  realtime.configured = false;
  state.feed = feed();
  state.marked = null;
  state.clearedAll = false;
  state.lastUnreadOnly = null;
  /*
   * Two of the server's ten, including the one the page's old hand-written sentence never named and this
   * feature's type union had never heard of.
   */
  state.kinds = [
    {
      kind: 'task_assigned',
      label: 'A task was assigned to you',
      needs_action: true,
      requires: 'tasks.view',
      subject: 'task',
    },
    {
      kind: 'lead_assigned',
      label: 'A lead is waiting for you',
      needs_action: true,
      requires: 'leads.view',
      subject: 'task',
    },
  ];
});

describe('the notification centre', function () {
  it('leads with what is waiting rather than with what is unread', async () => {
    await renderWithProviders(<NotificationsPage />);

    const summary = await screen.findByRole('region', { name: 'Notification summary' });
    const figures = within(summary).getAllByText(/Waiting on you|Unread|All time/);

    /*
     * "Twelve unread" measures how long somebody has been away; "two waiting on you" is a to-do list. The first
     * card is the one somebody should read.
     */
    expect(figures[0]).toHaveTextContent('Waiting on you');
    expect(within(summary).getByText('Needs you')).toBeInTheDocument();
  });

  it('says it is polling when realtime is off, rather than implying it is live', async () => {
    await renderWithProviders(<NotificationsPage />);

    /*
     * Not a failure — notifications are written either way — but a reader who thinks the page is live and sees
     * nothing move concludes the product is broken.
     */
    expect(await screen.findByText(/Live updates are off for this session/)).toBeInTheDocument();
    expect(screen.queryByText(/New notifications arrive as they happen/)).not.toBeInTheDocument();
  });

  it('says it is live only when both sides can connect', async () => {
    state.feed = feed({ is_live: true });
    realtime.configured = true;

    await renderWithProviders(<NotificationsPage />);

    expect(await screen.findByText(/New notifications arrive as they happen/)).toBeInTheDocument();
  });

  it('says it is polling when the server can push but this session cannot subscribe', async () => {
    state.feed = feed({ is_live: true });
    realtime.configured = false;

    await renderWithProviders(<NotificationsPage />);

    /*
     * The real defect this covers: a checkout with a broadcast connection configured and no frontend key told
     * every reader the page was live while nothing was subscribed. Trusting the server's half alone is how that
     * happens, and it is worse than saying live updates are off.
     */
    expect(await screen.findByText(/Live updates are off for this session/)).toBeInTheDocument();
  });

  it('marks a notification read when the thing it points at is opened', async () => {
    await renderWithProviders(<NotificationsPage />);

    await userEvent.click(await screen.findByRole('link', { name: 'Open the Approval request' }));

    /*
     * Opening the thing is reading the notification. Leaving it unread after somebody has acted on it is how a
     * badge comes to show a number nobody can clear.
     */
    expect(state.marked).toBe('notification-1');
  });

  it('says the thing it points at has been deleted rather than linking to nothing', async () => {
    state.feed = feed({
      notifications: [
        item({
          subject: {
            type: 'approval',
            type_label: 'Approval request',
            id: 'gone-1',
            label: null,
            exists: false,
            path: '/approvals',
          },
        }),
      ],
    });

    await renderWithProviders(<NotificationsPage />);

    expect(await screen.findByText('This Approval request has been deleted')).toBeInTheDocument();
    // The sentence that was announced stays exactly as it was: it is a record of having been told.
    expect(screen.getByText('Raise the Ramadan daily budget')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Open the/ })).not.toBeInTheDocument();
  });

  it('distinguishes something waiting on you from something merely unread', async () => {
    state.feed = feed({
      notifications: [
        item(),
        item({
          id: 'notification-2',
          kind: 'approval_decided',
          kind_label: 'Your request was answered',
          needs_action: false,
          title: 'Broaden the age range',
          is_unread: true,
          is_waiting: false,
        }),
      ],
    });

    await renderWithProviders(<NotificationsPage />);

    const list = await screen.findByRole('list', { name: 'Your notifications' });

    // Being told an answer is not a task. One badge says "waiting on you", the other only "unread".
    expect(within(list).getByText('Waiting on you')).toBeInTheDocument();
    expect(within(list).getByText('Unread')).toBeInTheDocument();
  });

  it('narrows to unread on request', async () => {
    await renderWithProviders(<NotificationsPage />);

    await screen.findByRole('region', { name: 'Notification summary' });
    expect(state.lastUnreadOnly).toBe(false);

    await userEvent.click(screen.getByRole('button', { name: 'Unread only' }));

    await vi.waitFor(() => {
      expect(state.lastUnreadOnly).toBe(true);
    });
  });

  it('clears everything in one action', async () => {
    await renderWithProviders(<NotificationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Mark everything read' }));

    expect(state.clearedAll).toBe(true);
  });

  it('offers no clear-all when nothing is unread', async () => {
    state.feed = feed({
      digest: { total: 3, unread: 0, waiting: 0, unread_by_kind: {} },
      notifications: [
        item({ is_unread: false, is_waiting: false, read_at: '2026-10-07T10:00:00+00:00' }),
      ],
    });

    await renderWithProviders(<NotificationsPage />);

    await screen.findByRole('region', { name: 'Notification summary' });
    expect(screen.queryByRole('button', { name: 'Mark everything read' })).not.toBeInTheDocument();
  });

  /*
   * The list is the server's, not a sentence kept in step by hand. The page used to name "four things" while
   * the server announced ten — including lead assignments, which this feature's own type union had never heard
   * of. A hand-written explanation is exactly where that drift hides, because it reads as prose rather than as
   * data nobody checked.
   */
  it('says what would put something here, from the server’s own vocabulary', async () => {
    state.feed = feed({
      notifications: [],
      digest: { total: 0, unread: 0, waiting: 0, unread_by_kind: {} },
    });

    await renderWithProviders(<NotificationsPage />);

    expect(await screen.findByText('Nothing yet')).toBeInTheDocument();
    /* Found rather than queried: the vocabulary is its own read, deliberately separate from the feed. */
    expect(await screen.findByText('A lead is waiting for you')).toBeInTheDocument();
    expect(screen.getByText('A task was assigned to you')).toBeInTheDocument();
    /* And the rule that decides who gets told, which is the part a reader cannot infer from the list. */
    expect(
      screen.getByText(/role already lets them see the thing it is about/),
    ).toBeInTheDocument();
  });

  /*
   * The vocabulary carries the permission each kind requires, and it stays out of the page: a reader needs to
   * know that a kind reaches only people who can already see what it is about, not that the gate is spelled
   * `leads.view`. A client viewer reaches this page too.
   */
  it('never prints the permission slug behind a kind', async () => {
    await renderWithProviders(<NotificationsPage />);

    await screen.findByRole('region', { name: 'Notification summary' });
    expect(screen.queryByText(/leads\.view|tasks\.view/)).not.toBeInTheDocument();
  });

  /* No invented list: the panel is an explanation of the feed, and an explanation it cannot read is absent. */
  it('says nothing about kinds when the vocabulary cannot be read', async () => {
    state.kinds = [];

    await renderWithProviders(<NotificationsPage />);

    await screen.findByRole('region', { name: 'Notification summary' });
    expect(screen.queryByText('What puts something here')).not.toBeInTheDocument();
  });
});
