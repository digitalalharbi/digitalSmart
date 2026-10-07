import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { OperationsPage } from './OperationsPage';
import type {
  AssigneeLoad,
  Task,
  TaskBoard,
  TaskCard,
  TaskColumn,
  TaskVocabulary,
  TaskWorkload,
} from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Alhambra' }] },
  }),
}));

const permissions = vi.hoisted(() => ({ current: ['tasks.view', 'tasks.manage'] as string[] }));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: { tasksView: 'tasks.view', tasksManage: 'tasks.manage' },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  board: null as TaskBoard | null,
  task: null as Task | null,
  vocabulary: null as TaskVocabulary | null,
  lastScope: null as Record<string, unknown> | null,
  moved: null as Record<string, unknown> | null,
  updated: null as Record<string, unknown> | null,
}));

vi.mock('./api', () => ({
  fetchTaskVocabulary: () => Promise.resolve(state.vocabulary),
  fetchTaskBoard: (scope: Record<string, unknown>) => {
    state.lastScope = scope;

    return Promise.resolve(state.board);
  },
  fetchTask: () => Promise.resolve(state.task),
  moveTask: (input: Record<string, unknown>) => {
    state.moved = input;

    return Promise.resolve(state.task);
  },
  updateTask: (input: Record<string, unknown>) => {
    state.updated = input;

    return Promise.resolve(state.task);
  },
  assignTask: () => Promise.resolve(state.task),
  openTask: () => Promise.resolve(state.task),
}));

function vocabulary(): TaskVocabulary {
  return {
    states: [
      {
        state: 'todo',
        label: 'To do',
        position: 0,
        is_open: true,
        is_waiting: false,
        needs_reason: false,
        allowed_next: ['in_progress', 'blocked', 'cancelled'],
      },
      {
        state: 'in_progress',
        label: 'In progress',
        position: 1,
        is_open: true,
        is_waiting: false,
        needs_reason: false,
        allowed_next: ['in_review', 'blocked', 'done', 'cancelled'],
      },
      {
        state: 'blocked',
        label: 'Blocked',
        position: 2,
        is_open: true,
        is_waiting: true,
        needs_reason: true,
        allowed_next: ['in_progress', 'cancelled'],
      },
      {
        state: 'done',
        label: 'Done',
        position: 4,
        is_open: false,
        is_waiting: false,
        needs_reason: false,
        allowed_next: ['in_progress'],
      },
      {
        state: 'cancelled',
        label: 'Cancelled',
        position: 5,
        is_open: false,
        is_waiting: false,
        needs_reason: true,
        allowed_next: ['todo'],
      },
    ],
    priorities: [
      { priority: 'low', label: 'Low', rank: 0, is_elevated: false },
      { priority: 'normal', label: 'Normal', rank: 1, is_elevated: false },
      { priority: 'high', label: 'High', rank: 2, is_elevated: true },
      { priority: 'urgent', label: 'Urgent', rank: 3, is_elevated: true },
    ],
    horizons: [
      { horizon: 'overdue', label: 'Overdue', rank: 0, needs_attention: true },
      { horizon: 'today', label: 'Due today', rank: 1, needs_attention: true },
      { horizon: 'this_week', label: 'Due within a week', rank: 2, needs_attention: false },
      { horizon: 'later', label: 'Later', rank: 3, needs_attention: false },
      { horizon: 'undated', label: 'No due date', rank: 4, needs_attention: false },
    ],
    subject_types: [{ type: 'creative_request', label: 'Creative request' }],
  };
}

function card(overrides: Partial<TaskCard> = {}): TaskCard {
  return {
    id: 'task-1',
    title: 'Rebuild the audience lists',
    state: 'todo',
    state_label: 'To do',
    priority: 'urgent',
    priority_label: 'Urgent',
    client_id: 'client-1',
    client_name: 'Alhambra',
    project_id: 'project-1',
    project_name: 'Always-on',
    assignee_id: 'user-1',
    assignee_name: 'Reem',
    due_on: '2026-10-02',
    horizon: 'overdue',
    horizon_label: 'Overdue',
    is_overdue: true,
    days_until_due: -5,
    blocked_reason: null,
    cancelled_reason: null,
    subject: null,
    ...overrides,
  };
}

function workload(overrides: Partial<TaskWorkload> = {}): TaskWorkload {
  return {
    total: 10,
    open: 8,
    done: 1,
    cancelled: 1,
    overdue: 2,
    undated: 1,
    unassigned: 1,
    elevated: 3,
    waiting: 1,
    by_state: { todo: 4, in_progress: 3, blocked: 1, done: 1, cancelled: 1 },
    by_horizon: { overdue: 2, today: 1, this_week: 3, later: 1, undated: 1 },
    completion_rate: 50,
    dated_share: 87.5,
    is_lateness_reportable: true,
    dated_share_floor: 80,
    ...overrides,
  };
}

function load(overrides: Partial<AssigneeLoad>[] = []): AssigneeLoad[] {
  const base: AssigneeLoad[] = [
    {
      user_id: 'user-1',
      name: 'Reem',
      is_unassigned: false,
      open: 5,
      overdue: 2,
      by_priority: { low: 0, normal: 2, high: 2, urgent: 1 },
    },
    {
      user_id: null,
      name: null,
      is_unassigned: true,
      open: 1,
      overdue: 0,
      by_priority: { low: 1, normal: 0, high: 0, urgent: 0 },
    },
  ];

  return overrides.length === 0
    ? base
    : base.map((row, index) => ({ ...row, ...overrides[index] }));
}

function column(overrides: Partial<TaskColumn> = {}): TaskColumn {
  return { state: 'todo', label: 'To do', total: 4, tasks: [card()], ...overrides };
}

function board(overrides: Partial<TaskBoard> = {}): TaskBoard {
  return {
    columns: [
      column(),
      column({ state: 'in_progress', label: 'In progress', total: 3, tasks: [] }),
      column({ state: 'blocked', label: 'Blocked', total: 1, tasks: [] }),
      column({ state: 'done', label: 'Done', total: 1, tasks: [] }),
      column({ state: 'cancelled', label: 'Cancelled', total: 1, tasks: [] }),
    ],
    workload: workload(),
    load: load(),
    priorities_present: ['low', 'normal', 'high', 'urgent'],
    is_truncated: false,
    as_of: '2026-10-07T12:00:00+00:00',
    ...overrides,
  };
}

function task(overrides: Partial<Task> = {}): Task {
  return {
    ...card(),
    description: 'Split the lookalikes by recency.',
    started_at: null,
    completed_at: null,
    state_changed_at: '2026-10-05T09:00:00+00:00',
    created_at: '2026-10-01T09:00:00+00:00',
    working_days: null,
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['tasks.view', 'tasks.manage'];
  state.vocabulary = vocabulary();
  state.board = board();
  state.task = task();
  state.lastScope = null;
  state.moved = null;
  state.updated = null;
});

describe('the operations board', function () {
  it('publishes the undated count beside the overdue one', async () => {
    await renderWithProviders(<OperationsPage />);

    const summary = await screen.findByRole('region', { name: 'Board summary' });

    /*
     * The pairing the page exists for, and asserted inside the summary rather than on the page: "Overdue"
     * also appears on a card and in a chart's table, which is correct and is why the scope is explicit.
     * One figure counts work that is late; the other counts work that cannot be, and a page showing only
     * the first invites a reader to assume the rest is on time.
     */
    expect(within(summary).getByText('Overdue')).toBeInTheDocument();
    expect(within(summary).getByText('No due date')).toBeInTheDocument();
    expect(within(summary).getByText('Cannot be late')).toBeInTheDocument();
  });

  it('warns that the overdue figure is not a measure of lateness when most work is undated', async () => {
    state.board = board({
      workload: workload({ undated: 5, dated_share: 37.5, is_lateness_reportable: false }),
    });

    await renderWithProviders(<OperationsPage />);

    /*
     * An alert rather than a footnote, and outside the chart frame so it survives the table view. A reader
     * who quotes "2 overdue" as the month's lateness has already drawn the wrong conclusion.
     */
    expect(
      await screen.findByText(/Read the overdue figure as a statement about the dated work/),
    ).toBeInTheDocument();
  });

  it('says there is no completion rate rather than showing nought percent', async () => {
    state.board = board({ workload: workload({ done: 0, cancelled: 0, completion_rate: null }) });

    await renderWithProviders(<OperationsPage />);

    // Zero percent and "nothing to measure" look identical on a card and mean opposite things.
    expect(await screen.findByText(/no completion rate to report/)).toBeInTheDocument();
  });

  it("shows a column's whole total, not the number of cards drawn", async () => {
    await renderWithProviders(<OperationsPage />);

    const board = await screen.findByRole('region', { name: 'The board' });
    const heading = within(board).getByRole('heading', { name: /To do/ });

    /*
     * One card on screen, four in the column. Publishing the page's length instead would be publishing
     * "the first fifty" the day a client got busy.
     */
    expect(heading).toHaveTextContent('4');
    expect(within(board).getAllByRole('button')).toHaveLength(1);
  });

  it('counts the unassigned pile as a row rather than leaving it out', async () => {
    await renderWithProviders(<OperationsPage />);

    const frame = await screen.findByRole('region', { name: 'Who is carrying the work' });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    // A chart of named people only would show a comfortable team beside work nobody has agreed to do.
    expect(within(frame).getByRole('row', { name: /Nobody/ })).toBeInTheDocument();
  });

  it('draws undated work as its own bucket in the due profile', async () => {
    await renderWithProviders(<OperationsPage />);

    const frame = await screen.findByRole('region', { name: 'When the open work is due' });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    expect(within(frame).getByRole('row', { name: /No due date/ })).toBeInTheDocument();
    expect(within(frame).getByRole('row', { name: /Later/ })).toBeInTheDocument();
  });
});

describe('a task', function () {
  it('offers only the moves the server allows', async () => {
    await renderWithProviders(<OperationsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Rebuild the audience lists/ }),
    );

    const moves = await screen.findByRole('region', { name: 'Where it can go' });

    /*
     * Taken from the vocabulary rather than hard-coded, so a target the board would refuse is never on
     * screen. Offering everything and showing an error teaches people to distrust the interface.
     */
    expect(within(moves).getByRole('button', { name: 'In progress' })).toBeInTheDocument();
    expect(within(moves).getByRole('button', { name: 'Blocked' })).toBeInTheDocument();
    expect(within(moves).queryByRole('button', { name: 'Done' })).not.toBeInTheDocument();
  });

  it('asks for a reason before blocking, not after being refused', async () => {
    await renderWithProviders(<OperationsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Rebuild the audience lists/ }),
    );

    const moves = await screen.findByRole('region', { name: 'Where it can go' });
    await userEvent.click(within(moves).getByRole('button', { name: 'Blocked' }));

    // Nothing is sent until the reason exists. The server and a database CHECK refuse it too.
    expect(state.moved).toBeNull();
    expect(await screen.findByLabelText(/What is blocking it/)).toBeInTheDocument();

    await userEvent.type(
      screen.getByLabelText(/What is blocking it/),
      'Waiting on template access.',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Save and move' }));

    expect(state.moved).toMatchObject({
      state: 'blocked',
      reason: 'Waiting on template access.',
    });
  });

  it('keeps showing why work was blocked after it has been unblocked', async () => {
    state.task = task({
      state: 'in_progress',
      state_label: 'In progress',
      blocked_reason: "Waiting on the client's developer.",
    });

    await renderWithProviders(<OperationsPage />);
    await userEvent.click(
      await screen.findByRole('button', { name: /Rebuild the audience lists/ }),
    );

    // The answer to "why did this take three weeks". Clearing it would lose the only record of the delay.
    expect(
      await screen.findByText(/Was blocked: Waiting on the client's developer\./),
    ).toBeInTheDocument();
  });

  it('says a deleted subject is deleted rather than linking to nothing', async () => {
    state.task = task({
      subject: {
        type: 'creative_request',
        type_label: 'Creative request',
        id: 'gone-1',
        label: null,
        exists: false,
        path: '/creative-hub',
      },
    });

    await renderWithProviders(<OperationsPage />);
    await userEvent.click(
      await screen.findByRole('button', { name: /Rebuild the audience lists/ }),
    );

    /*
     * There is no foreign key behind a task's subject, so this case is real. A link that looks live and
     * goes nowhere is worse than a sentence.
     */
    expect(await screen.findByText('This Creative request has been deleted')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Creative request/ })).not.toBeInTheDocument();
  });

  it('shows no due date as a sentence, and unknown working days as a dash', async () => {
    state.task = task({
      due_on: null,
      horizon: 'undated',
      horizon_label: 'No due date',
      days_until_due: null,
      is_overdue: false,
    });

    await renderWithProviders(<OperationsPage />);
    await userEvent.click(
      await screen.findByRole('button', { name: /Rebuild the audience lists/ }),
    );

    /*
     * A blank reads as a date somebody forgot to load. Zero working days is a claim; a dash is the truth
     * while nobody has recorded a start.
     */
    const detail = await screen.findByText('Working days');
    expect(detail).toBeInTheDocument();
    expect(screen.getAllByText('No due date').length).toBeGreaterThan(0);
  });

  it('lets a due date be cleared, not only set', async () => {
    await renderWithProviders(<OperationsPage />);
    await userEvent.click(
      await screen.findByRole('button', { name: /Rebuild the audience lists/ }),
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Clear the date' }));

    // A board where a deadline can be set but never removed accumulates dates nobody agreed to.
    expect(state.updated).toMatchObject({ dueOn: '' });
  });

  it('hides the moves from somebody who may only read the board', async () => {
    permissions.current = ['tasks.view'];

    await renderWithProviders(<OperationsPage />);
    await userEvent.click(
      await screen.findByRole('button', { name: /Rebuild the audience lists/ }),
    );

    await screen.findByText('Split the lookalikes by recency.');
    // Hiding is the courtesy; the server refuses the write regardless.
    expect(screen.queryByRole('region', { name: 'Where it can go' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Put work on the board' })).not.toBeInTheDocument();
  });
});

describe('narrowing the board', function () {
  it('asks the server to resolve "mine" rather than deciding who the actor is', async () => {
    await renderWithProviders(<OperationsPage />);

    await screen.findByRole('region', { name: 'Board summary' });
    expect(state.lastScope).toMatchObject({ assignee: null });

    await userEvent.click(screen.getByRole('button', { name: 'Mine' }));

    /*
     * The literal `me`, resolved server-side. A front end sending its own idea of who the actor is would be
     * one session-restore defect away from showing somebody else's week.
     */
    await vi.waitFor(() => {
      expect(state.lastScope).toMatchObject({ assignee: 'me' });
    });
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<OperationsPage />);

    expect(
      await screen.findByText('The operations board is not part of your access'),
    ).toBeInTheDocument();
  });
});
