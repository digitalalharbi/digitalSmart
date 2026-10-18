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
  WriteOutcome,
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
    providerWritesExecute: 'provider_writes.execute',
  },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  queue: null as ApprovalQueue | null,
  request: null as ApprovalRequest | null,
  vocabulary: null as ApprovalVocabulary | null,
  decided: null as Record<string, unknown> | null,
  enacted: null as Record<string, unknown> | null,
  asked: null as Record<string, unknown> | null,
  carriedOut: null as Record<string, unknown> | null,
  outcome: null as WriteOutcome | null,
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
  requestApproval: (input: Record<string, unknown>) => {
    state.asked = input;

    return Promise.resolve(state.request);
  },
  carryOutApproval: (input: Record<string, unknown>) => {
    state.carriedOut = input;

    return state.failWith === null
      ? Promise.resolve({ request: state.request, outcome: state.outcome })
      : Promise.reject(state.failWith);
  },
}));

/** An approval this product can actually carry out: a provider whose write is documented, and a campaign named. */
function writable(): Partial<ApprovalRequest> {
  return {
    id: 'approval-2',
    kind: 'campaign_pause',
    kind_label: 'Pause a campaign',
    state: 'approved',
    state_label: 'Approved',
    enactment: 'awaiting_hand',
    enactment_label: 'Waiting to be carried out',
    can_be_carried_out_here: true,
    carry_out_blocked_because: null,
    proposal: { provider: 'meta', campaign_id: '23850000000000001' },
    decided_by_name: 'Mohammed',
    decided_at: '2026-09-28T09:00:00+00:00',
    waiting_days: 9,
  };
}

function outcome(overrides: Partial<WriteOutcome> = {}): WriteOutcome {
  return {
    is_confirmed: true,
    operation: 'pause_campaign',
    provider: 'meta',
    provider_label: 'Meta Ads',
    confirmed_state: 'PAUSED',
    source: 'sandbox',
    failure: null,
    failure_label: null,
    guidance: null,
    safe_to_retry: null,
    provider_message: null,
    provider_request_id: 'req_7f3a',
    source_label: 'Sandbox',
    summary: 'Meta Ads reported "PAUSED" when the campaign was read back.',
    ...overrides,
  };
}

function vocabulary(): ApprovalVocabulary {
  return {
    kinds: [
      {
        kind: 'spend_change',
        label: 'Change spend',
        touches_live_spend: true,
        is_enacted_here: false,
        enactment_blocked_because:
          'Approving this changes no live budget or bid by itself. Where the request names a campaign on a platform whose write is documented, this product can carry it out; otherwise somebody makes the change in the platform and marks it done here.',
        decided_with: 'budgets.approve',
        usual_subject: 'budget',
      },
      {
        /* The one kind in this vocabulary whose write this product has read in Meta's own documentation. */
        kind: 'campaign_pause',
        label: 'Pause a campaign',
        touches_live_spend: false,
        is_enacted_here: false,
        enactment_blocked_because:
          'Approving this starts or stops nothing by itself. Where the request names a campaign on a platform whose write is documented, this product can carry it out; otherwise somebody makes the change in the platform and marks it done here.',
        decided_with: null,
        usual_subject: 'campaign',
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
    rationale: '',
    proposal: {},
    origin: 'person',
    agent: null,
    evidence: null,
    risk: null,
    expires_at: null,
    state: 'pending',
    state_label: 'Waiting for a decision',
    enactment: 'not_required',
    enactment_label: 'Nothing to carry out',
    touches_live_spend: true,
    is_enacted_here: false,
    enactment_blocked_because:
      'Approving this changes no live budget or bid by itself. Where the request names a campaign on a platform whose write is documented, this product can carry it out; otherwise somebody makes the change in the platform and marks it done here.',
    /*
     * Most approvals do not name a platform campaign, so this is the ordinary case: the request says why it
     * cannot be carried out here rather than offering a button that would guess which campaign was meant.
     */
    can_be_carried_out_here: false,
    carry_out_blocked_because:
      'This request does not name a platform campaign, so this product cannot carry it out. Most approvals do not — make the change in the platform and record it here.',
    proposal_matches_decision: true,
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
  state.asked = null;
  state.carriedOut = null;
  state.outcome = outcome();
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
    expect(await screen.findByText(/changes no live budget or bid by itself/)).toBeInTheDocument();
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

  it('raises a request with the proposal exactly as it was typed', async () => {
    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Raise a request' }));

    const dialog = await screen.findByRole('dialog');

    await userEvent.selectOptions(
      within(dialog).getByLabelText(/What kind of change/),
      'campaign_pause',
    );
    await userEvent.type(
      within(dialog).getByLabelText(/What the queue will call it/),
      'Pause the Eid prospecting campaign',
    );
    await userEvent.type(
      within(dialog).getByLabelText(/Why it is wanted/),
      'Cost per purchase has doubled over four days.',
    );
    await userEvent.type(within(dialog).getByLabelText('Field'), 'external_campaign_id');
    await userEvent.type(within(dialog).getByLabelText('Value'), '23850000000000001');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send for approval' }));

    /*
     * Keys and values, not a shape. This product does not model each platform's schema to describe a change to
     * it, and the fingerprint a decision names covers exactly these characters — so a form that coerced them
     * would have a decider approve something nobody wrote.
     */
    expect(state.asked).toMatchObject({
      kind: 'campaign_pause',
      title: 'Pause the Eid prospecting campaign',
      proposal: { external_campaign_id: '23850000000000001' },
    });
  });

  it('refuses a request with nothing proposed', async () => {
    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Raise a request' }));

    const dialog = await screen.findByRole('dialog');

    await userEvent.selectOptions(
      within(dialog).getByLabelText(/What kind of change/),
      'tracking_change',
    );
    await userEvent.type(
      within(dialog).getByLabelText(/What the queue will call it/),
      'Count the newsletter sign-up',
    );
    await userEvent.type(
      within(dialog).getByLabelText(/Why it is wanted/),
      'It is the only signal we have on the content side.',
    );
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send for approval' }));

    // An approval with an empty proposal is a title somebody is asked to agree to.
    expect(state.asked).toBeNull();
    expect(await screen.findByText(/Say what is being proposed/)).toBeInTheDocument();
  });

  it('says at the moment of asking that approving will not make the change', async () => {
    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Raise a request' }));

    const dialog = await screen.findByRole('dialog');

    await userEvent.selectOptions(
      within(dialog).getByLabelText(/What kind of change/),
      'spend_change',
    );

    /*
     * The requester learns it too, not only the decider. Somebody who raises a spend change believing approval
     * raises the budget writes the client an email that is wrong by one working day.
     */
    expect(within(dialog).getByText('Changes live spend')).toBeInTheDocument();
    expect(within(dialog).getByText(/changes no live budget or bid by itself/)).toBeInTheDocument();
  });

  it('offers no way to ask to somebody who may only read the queue', async () => {
    permissions.current = ['approvals.view', 'approvals.decide'];

    await renderWithProviders(<ApprovalsPage />);

    await screen.findByRole('heading', { level: 1, name: 'Approvals' });

    // Asking and deciding are separate permissions, and the queue is where asking happens.
    expect(screen.queryByRole('button', { name: 'Raise a request' })).not.toBeInTheDocument();
  });

  it('offers no platform button where the provider write is not documented', async () => {
    permissions.current = [
      'approvals.view',
      'approvals.request',
      'approvals.decide',
      'provider_writes.execute',
    ];

    state.request = request({
      id: 'approval-2',
      state: 'approved',
      state_label: 'Approved',
      enactment: 'awaiting_hand',
      enactment_label: 'Waiting to be carried out',
      waiting_days: 9,
    });

    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting set/ }));

    /*
     * The ordinary case, and it is published rather than hidden: the reason is what sends somebody to the
     * platform, which is the right place when this product cannot name the campaign.
     */
    expect(await screen.findByText(/does not name a platform campaign/)).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Carry this out on the platform' }),
    ).not.toBeInTheDocument();
  });

  it('asks the platform and reports what it read back', async () => {
    permissions.current = [
      'approvals.view',
      'approvals.request',
      'approvals.decide',
      'provider_writes.execute',
    ];

    state.request = request(writable());

    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting set/ }));
    await userEvent.click(
      await screen.findByRole('button', { name: /Carry this out on the platform/ }),
    );

    expect(state.carriedOut).toMatchObject({ requestId: 'approval-2' });

    /*
     * A confirmation names the state the provider was read back as, not the state this product asked for. And
     * a sandbox write says so on the same panel — a simulated change recorded as a real one is the one failure
     * mode this whole feature has to not have.
     */
    expect(await screen.findByText('Meta Ads confirmed: PAUSED')).toBeInTheDocument();
    expect(screen.getByText(/This was a sandbox write/)).toBeInTheDocument();
  });

  it('names the failure, what to do, and when not to try again', async () => {
    permissions.current = [
      'approvals.view',
      'approvals.request',
      'approvals.decide',
      'provider_writes.execute',
    ];

    state.request = request(writable());
    state.outcome = outcome({
      is_confirmed: false,
      confirmed_state: null,
      failure: 'unknown',
      failure_label: 'The outcome is unknown',
      guidance: 'Check the campaign in the platform before trying again.',
      safe_to_retry: false,
      provider_message: 'Request timed out',
      provider_request_id: 'req_91bc',
    });

    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting set/ }));
    await userEvent.click(
      await screen.findByRole('button', { name: /Carry this out on the platform/ }),
    );

    /*
     * The failure that must not be retried. A reader told only "it failed" presses the button again, and a
     * second attempt after an unknown outcome is how a campaign gets paused twice or a budget set twice.
     */
    expect(await screen.findByText(/The outcome is unknown/)).toBeInTheDocument();
    expect(screen.getByText(/Do not try this again/)).toBeInTheDocument();
    expect(screen.getByText(/Request timed out/)).toBeInTheDocument();
  });

  it('withholds the platform button from somebody who may decide but not write', async () => {
    state.request = request(writable());

    await renderWithProviders(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting set/ }));

    await screen.findByText(/Cost per purchase has held/);

    /*
     * Deciding and writing are separate permissions on purpose: approving a change and being the credentials
     * that move money are different acts. "I have made this change" stays — recording what a person did by
     * hand needs no provider permission.
     */
    expect(
      screen.queryByRole('button', { name: 'Carry this out on the platform' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'I have made this change' })).toBeInTheDocument();
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
