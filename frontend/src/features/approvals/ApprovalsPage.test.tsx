import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ApprovalsPage } from './ApprovalsPage';
import type {
  ApprovalLoad,
  ApprovalQueue,
  ApprovalRequest,
  ApprovalRow,
  ApprovalVocabulary,
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

const permissions = vi.hoisted(() => ({
  current: ['approvals.view', 'approvals.request', 'approvals.decide'] as string[],
}));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: {
    approvalsView: 'approvals.view',
    approvalsRequest: 'approvals.request',
    approvalsDecide: 'approvals.decide',
  },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  queue: null as ApprovalQueue | null,
  request: null as ApprovalRequest | null,
  vocabulary: null as ApprovalVocabulary | null,
  decided: null as Record<string, unknown> | null,
  enacted: null as Record<string, unknown> | null,
  failWith: null as Error | null,
}));

vi.mock('./api', () => ({
  fetchApprovalVocabulary: () => Promise.resolve(state.vocabulary),
  fetchApprovalQueue: () => Promise.resolve(state.queue),
  fetchApproval: () => Promise.resolve(state.request),
  decideApproval: (input: Record<string, unknown>) => {
    state.decided = input;

    return state.failWith === null
      ? Promise.resolve(state.request)
      : Promise.reject(state.failWith);
  },
  withdrawApproval: () => Promise.resolve(state.request),
  recordEnactment: (input: Record<string, unknown>) => {
    state.enacted = input;

    return Promise.resolve(state.request);
  },
  requestApproval: () => Promise.resolve(state.request),
}));

function vocabulary(): ApprovalVocabulary {
  return {
    kinds: [
      {
        kind: 'spend_change',
        label: 'Change spend',
        touches_live_spend: true,
        is_enacted_here: false,
        enactment_blocked_because:
          'This product does not change live budgets or bids. Once this is approved, somebody makes the change in the platform and marks it done here.',
        decided_with: 'budgets.approve',
        usual_subject: 'budget',
      },
      {
        kind: 'tracking_change',
        label: 'Change tracking',
        touches_live_spend: false,
        is_enacted_here: false,
        enactment_blocked_because: 'This product does not write to analytics properties.',
        decided_with: 'tracking.manage',
        usual_subject: null,
      },
    ],
    states: [
      {
        state: 'pending',
        label: 'Waiting for a decision',
        position: 0,
        is_pending: true,
        needs_reason: false,
        is_amendable: true,
      },
      {
        state: 'approved',
        label: 'Approved',
        position: 1,
        is_pending: false,
        needs_reason: false,
        is_amendable: false,
      },
    ],
    enactments: [
      {
        enactment: 'awaiting_hand',
        label: 'Waiting to be carried out',
        is_outstanding: true,
        is_settled: false,
      },
    ],
    ages: [
      { age: 'today', label: 'Today', rank: 0, needs_attention: false },
      { age: 'days', label: 'One to three days', rank: 1, needs_attention: false },
      { age: 'week', label: 'Four to seven days', rank: 2, needs_attention: true },
      { age: 'stale', label: 'Over a week', rank: 3, needs_attention: true },
    ],
    patience_days: 3,
  };
}

function row(overrides: Partial<ApprovalRow> = {}): ApprovalRow {
  return {
    id: 'approval-1',
    kind: 'spend_change',
    kind_label: 'Change spend',
    title: 'Raise the Ramadan daily budget',
    state: 'pending',
    state_label: 'Waiting for a decision',
    enactment: 'not_required',
    enactment_label: 'Nothing to carry out',
    touches_live_spend: true,
    is_enacted_here: false,
    enactment_blocked_because:
      'This product does not change live budgets or bids. Once this is approved, somebody makes the change in the platform and marks it done here.',
    fingerprint: 'abc123def456abc123def456abc123def456abc123def456abc123def4560000',
    fingerprint_short: 'abc123def456',
    client_id: 'client-1',
    client_name: 'Alhambra',
    project_id: 'project-1',
    project_name: 'Always-on',
    requested_by_name: 'Reem',
    created_at: '2026-10-01T09:00:00+00:00',
    decided_by_name: null,
    decided_at: null,
    age: 'week',
    age_label: 'Four to seven days',
    waiting_days: 6,
    subject: null,
    ...overrides,
  };
}

function load(overrides: Partial<ApprovalLoad> = {}): ApprovalLoad {
  return {
    pending: 2,
    pending_touching_spend: 1,
    approved: 3,
    declined: 1,
    withdrawn: 0,
    outstanding: 1,
    confirmed: 2,
    abandoned: 0,
    oldest_outstanding_days: 9,
    oldest_pending_days: 6,
    pending_by_age: { today: 1, days: 0, week: 1, stale: 0 },
    outstanding_by_age: { today: 0, days: 0, week: 0, stale: 1 },
    pending_by_kind: { spend_change: 1, tracking_change: 1 },
    carried_out_rate: 100,
    approval_rate: 75,
    needs_attention: true,
    patience_days: 3,
    ...overrides,
  };
}

function queue(overrides: Partial<ApprovalQueue> = {}): ApprovalQueue {
  return {
    pending: [row()],
    outstanding: [
      row({
        id: 'approval-2',
        title: 'Pause the retargeting set',
        kind: 'campaign_pause',
        kind_label: 'Pause a campaign',
        state: 'approved',
        state_label: 'Approved',
        enactment: 'awaiting_hand',
        enactment_label: 'Waiting to be carried out',
        decided_by_name: 'Mohammed',
        decided_at: '2026-09-28T09:00:00+00:00',
        age: 'stale',
        age_label: 'Over a week',
        waiting_days: 9,
      }),
    ],
    settled: [],
    load: load(),
    as_of: '2026-10-07T12:00:00+00:00',
    ...overrides,
  };
}

function request(overrides: Partial<ApprovalRequest> = {}): ApprovalRequest {
  return {
    ...row(),
    rationale: 'Cost per purchase has held under target for nine days.',
    proposal: { from_daily_minor: 50_000, to_daily_minor: 80_000, currency: 'SAR' },
    decision_reason: null,
    withdrawn_reason: null,
    enactment_note: null,
    enacted_by_name: null,
    enacted_at: null,
    supersedes_id: null,
    decision_days: null,
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['approvals.view', 'approvals.request', 'approvals.decide'];
  state.vocabulary = vocabulary();
  state.queue = queue();
  state.request = request();
  state.decided = null;
  state.enacted = null;
  state.failWith = null;
});

describe('the approval queue', function () {
  it('leads with approved work nobody has carried out', async () => {
    await renderWithProviders(<ApprovalsPage />);

    const summary = await screen.findByRole('region', { name: 'Approval summary' });

    /*
     * The figure that justifies the module. Not a process metric: a list of things somebody currently believes
     * that may not be true.
     */
    expect(within(summary).getByText('Approved, not yet done')).toBeInTheDocument();
    expect(within(summary).getByText('Nothing has changed yet')).toBeInTheDocument();
  });

  it('says how long the oldest unmade change has been waiting', async () => {
    await renderWithProviders(<ApprovalsPage />);

    /*
     * An alert above the chart and outside its frame, so it survives the table view. Until somebody records
     * making the change, nothing has happened on the platform.
     */
    expect(await screen.findByText(/the oldest approved 9 days ago/)).toBeInTheDocument();
  });

  it('keeps the two queues apart', async () => {
    await renderWithProviders(<ApprovalsPage />);

    const pending = await screen.findByRole('region', { name: 'Waiting for a decision' });
    const outstanding = screen.getByRole('region', {
      name: 'Approved, waiting to be carried out',
    });

    /*
     * Two lists, not one. A request nobody has answered and a change nobody has made have different owners,
     * and merging them hides the second — which is the one that ends with a client being misinformed.
     */
    expect(within(pending).getByText('Raise the Ramadan daily budget')).toBeInTheDocument();
    expect(within(outstanding).getByText('Pause the retargeting set')).toBeInTheDocument();
  });

  it('says so plainly when nothing is waiting to be carried out', async () => {
    state.queue = queue({
      outstanding: [],
      load: load({ outstanding: 0, oldest_outstanding_days: null }),
    });

    await renderWithProviders(<ApprovalsPage />);

    // A good state said out loud is the difference between a reassurance and a page that failed to load.
    expect(
      await screen.findByText('Nothing approved is waiting to be carried out.'),
    ).toBeInTheDocument();
  });

  it('reports no rates rather than nought percent before anything settles', async () => {
    state.queue = queue({
      load: load({ approval_rate: null, carried_out_rate: null, approved: 0, declined: 0 }),
    });

    await renderWithProviders(<ApprovalsPage />);

    expect(await screen.findByText(/no approval rate to report/)).toBeInTheDocument();
  });

  it('draws both queues on one ordered axis, with the figures in a table', async () => {
    await renderWithProviders(<ApprovalsPage />);

    const frame = await screen.findByRole('region', { name: 'Where the waiting is' });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    const stale = within(frame).getByRole('row', { name: /Over a week/ });

    // One pending row in the four-to-seven bucket, one outstanding in "over a week".
    expect(stale).toBeInTheDocument();
    expect(within(frame).getByRole('row', { name: /Four to seven days/ })).toBeInTheDocument();
  });

  it('keeps a kind with nothing waiting in the table and out of the chart', async () => {
    state.queue = queue({
      load: load({ pending_by_kind: { spend_change: 1, tracking_change: 0 } }),
    });

    await renderWithProviders(<ApprovalsPage />);

    const frame = await screen.findByRole('region', { name: 'What is waiting for a decision' });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    // A bar of zero reads as a measurement of nothing; a zero in a table is information.
    expect(within(frame).getByRole('row', { name: /Change tracking/ })).toBeInTheDocument();
  });
});

describe('a request', function () {
  it('says on the request that approving it will not make the change', async () => {
    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Raise the Ramadan daily budget/ }),
    );

    /*
     * Before the buttons, not after the decision. Without this sentence a decider presses Approve believing the
     * budget is now raised, and tells a client so.
     */
    expect(await screen.findByText(/does not change live budgets or bids/)).toBeInTheDocument();
  });

  it('sends back the fingerprint it displayed', async () => {
    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Raise the Ramadan daily budget/ }),
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));

    /*
     * The guarantee the module rests on: a decision names the proposal it read. A mismatch is answered 409 with
     * the proposal as it stands, rather than silently recording an approval of something unseen.
     */
    expect(state.decided).toMatchObject({
      outcome: 'approved',
      fingerprint: 'abc123def456abc123def456abc123def456abc123def456abc123def4560000',
    });
  });

  it("shows the server's own words when the proposal changed under the reader", async () => {
    const { ApiError } = await import('@/shared/api/client');

    state.failWith = new ApiError(
      'This proposal was amended after you opened it. Read it again before deciding.',
      409,
    );

    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Raise the Ramadan daily budget/ }),
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));

    // A re-read, not a retry. The generic failure message would send somebody back to press the same button.
    expect(await screen.findByText(/amended after you opened it/)).toBeInTheDocument();
  });

  it('shows the whole proposal, field by field, with its version', async () => {
    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Raise the Ramadan daily budget/ }),
    );

    const proposal = await screen.findByRole('region', { name: 'What is being proposed' });

    /*
     * Never summarised. This product deliberately does not model a platform's schema to describe a change to
     * it, so whatever the requester wrote is what the decider reads.
     */
    expect(within(proposal).getByText('to_daily_minor')).toBeInTheDocument();
    expect(within(proposal).getByText('80000')).toBeInTheDocument();
    expect(within(proposal).getByText('Version abc123def456')).toBeInTheDocument();
  });

  it('asks for a reason before declining', async () => {
    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Raise the Ramadan daily budget/ }),
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Decline' }));

    expect(state.decided).toBeNull();
    expect(await screen.findByLabelText(/Why it is being declined/)).toBeInTheDocument();
  });

  it('offers to record the change on something approved and not yet done', async () => {
    state.request = request({
      id: 'approval-2',
      state: 'approved',
      state_label: 'Approved',
      enactment: 'awaiting_hand',
      enactment_label: 'Waiting to be carried out',
      decided_by_name: 'Mohammed',
      decided_at: '2026-09-28T09:00:00+00:00',
      waiting_days: 9,
    });

    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting set/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'I have made this change' }));

    // Somebody's claim, with their name and the time on it. The product did not read the platform.
    expect(state.enacted).toMatchObject({ enactment: 'confirmed' });
  });

  it('offers no decision to somebody who may only ask', async () => {
    permissions.current = ['approvals.view', 'approvals.request'];

    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Raise the Ramadan daily budget/ }),
    );

    await screen.findByText(/Cost per purchase has held/);

    /*
     * The separation the module exists for. Hiding is the courtesy; the server refuses the request regardless,
     * and for a spend change it also requires the permission that governs budgets.
     */
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Withdraw' })).toBeInTheDocument();
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<ApprovalsPage />);

    expect(await screen.findByText('Approvals are not part of your access')).toBeInTheDocument();
  });
});
