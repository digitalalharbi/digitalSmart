import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { RequestsPage } from './RequestsPage';
import type {
  ClientRequest,
  ClientRequestCard,
  IntakeColumn,
  IntakeLoad,
  IntakeQueue,
  RequestVocabulary,
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
  current: ['client_requests.submit', 'client_requests.view', 'client_requests.triage'] as string[],
}));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: {
    clientRequestsSubmit: 'client_requests.submit',
    clientRequestsView: 'client_requests.view',
    clientRequestsTriage: 'client_requests.triage',
  },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  queue: null as IntakeQueue | null,
  request: null as ClientRequest | null,
  vocabulary: null as RequestVocabulary | null,
  triaged: null as Record<string, unknown> | null,
  answered: null as Record<string, unknown> | null,
  attached: null as Record<string, unknown> | null,
  attachFailsWith: null as Error | null,
  promised: null as Record<string, unknown> | null,
  raised: null as Record<string, unknown> | null,
  submitted: null as Record<string, unknown> | null,
  assigned: null as Record<string, unknown> | null,
  owners: [] as { id: string; name: string }[],
}));

vi.mock('./api', () => ({
  fetchRequestVocabulary: () => Promise.resolve(state.vocabulary),
  fetchIntakeQueue: () => Promise.resolve(state.queue),
  fetchClientRequest: () => Promise.resolve(state.request),
  fetchRequestOwners: () => Promise.resolve(state.owners),
  assignRequestOwner: (input: Record<string, unknown>) => {
    state.assigned = input;

    return Promise.resolve(state.request);
  },
  triageClientRequest: (input: Record<string, unknown>) => {
    state.triaged = input;

    return Promise.resolve(state.request);
  },
  answerClientRequest: (input: Record<string, unknown>) => {
    state.answered = input;

    return Promise.resolve(state.request);
  },
  attachToClientRequest: (input: Record<string, unknown>) => {
    state.attached = input;

    return state.attachFailsWith === null
      ? Promise.resolve(state.request)
      : Promise.reject(state.attachFailsWith);
  },
  attachmentUrl: (id: string) => `/api/client-request-attachments/${id}`,
  promiseClientRequest: (input: Record<string, unknown>) => {
    state.promised = input;

    return Promise.resolve(state.request);
  },
  raiseTaskForRequest: (input: Record<string, unknown>) => {
    state.raised = input;

    return Promise.resolve(state.request);
  },
  submitClientRequest: (input: Record<string, unknown>) => {
    state.submitted = input;

    return Promise.resolve(state.request);
  },
}));

function vocabulary(): RequestVocabulary {
  return {
    states: [
      {
        state: 'submitted',
        label: 'New',
        position: 0,
        is_open: true,
        is_on_the_agency: true,
        is_on_the_client: false,
        needs_reason: false,
        allowed_next: ['needs_information', 'accepted', 'declined'],
      },
      {
        state: 'needs_information',
        label: 'Waiting on the client',
        position: 1,
        is_open: true,
        is_on_the_agency: false,
        is_on_the_client: true,
        needs_reason: true,
        allowed_next: ['submitted', 'accepted', 'declined'],
      },
      {
        state: 'accepted',
        label: 'Accepted',
        position: 2,
        is_open: true,
        is_on_the_agency: true,
        is_on_the_client: false,
        needs_reason: false,
        allowed_next: ['in_progress', 'declined'],
      },
      {
        state: 'declined',
        label: 'Declined',
        position: 5,
        is_open: false,
        is_on_the_agency: false,
        is_on_the_client: false,
        needs_reason: true,
        allowed_next: ['submitted'],
      },
    ],
    kinds: [
      { kind: 'change', label: 'A change to something running', produces_work: true },
      { kind: 'question', label: 'A question', produces_work: false },
    ],
    urgencies: [
      { urgency: 'whenever', label: 'Whenever you can', rank: 0 },
      { urgency: 'this_month', label: 'This month', rank: 1 },
      { urgency: 'this_week', label: 'This week', rank: 2 },
      { urgency: 'urgent', label: 'Urgent', rank: 3 },
    ],
    audiences: [
      { audience: 'internal', label: 'Internal only', is_visible_to_client: false },
      { audience: 'client', label: 'The client will see this', is_visible_to_client: true },
    ],
    patience_days: 2,
  };
}

function card(overrides: Partial<ClientRequestCard> = {}): ClientRequestCard {
  return {
    id: 'request-1',
    client_id: 'client-1',
    client_name: 'Alhambra',
    kind: 'change',
    kind_label: 'A change to something running',
    title: 'Pause the retargeting over Eid',
    state: 'submitted',
    state_label: 'New',
    is_on_the_client: false,
    stated_urgency: 'urgent',
    stated_urgency_label: 'Urgent',
    project_id: 'project-1',
    project_name: 'Always-on',
    wanted_by: '2026-10-14',
    promised_at: null,
    promised_by_name: null,
    is_overdue: false,
    is_unanswered: true,
    waiting_for_answer_days: 5,
    created_at: '2026-10-03T09:00:00+00:00',
    ...overrides,
  };
}

function load(overrides: Partial<IntakeLoad> = {}): IntakeLoad {
  return {
    total: 6,
    open: 4,
    on_the_agency: 3,
    on_the_client: 1,
    delivered: 1,
    declined: 1,
    withdrawn: 0,
    promised: 1,
    unpromised: 2,
    overdue: 0,
    called_urgent: 1,
    oldest_unanswered_days: 5,
    by_state: { submitted: 2, needs_information: 1, accepted: 1, declined: 1 },
    by_kind: { change: 3, question: 1 },
    by_urgency: { whenever: 1, this_month: 1, this_week: 1, urgent: 1 },
    delivery_rate: 50,
    promised_share: 33.3,
    needs_attention: true,
    patience_days: 2,
    ...overrides,
  };
}

function column(overrides: Partial<IntakeColumn> = {}): IntakeColumn {
  return { state: 'submitted', label: 'New', total: 2, requests: [card()], ...overrides };
}

function queue(overrides: Partial<IntakeQueue> = {}): IntakeQueue {
  return {
    columns: [
      column(),
      column({
        state: 'needs_information',
        label: 'Waiting on the client',
        total: 1,
        requests: [],
      }),
      column({ state: 'accepted', label: 'Accepted', total: 1, requests: [] }),
      column({ state: 'declined', label: 'Declined', total: 1, requests: [] }),
    ],
    load: load(),
    is_agency_view: true,
    can_triage: true,
    as_of: '2026-10-08T12:00:00+00:00',
    ...overrides,
  };
}

function request(overrides: Partial<ClientRequest> = {}): ClientRequest {
  return {
    ...card(),
    detail: 'We will be closed for the holiday.',
    declined_reason: null,
    needed_information: null,
    submitted_by_name: 'Reem',
    owner_id: 'user-9',
    owner_name: 'Mohammed',
    first_answered_at: null,
    delivered_at: null,
    state_changed_at: '2026-10-03T09:00:00+00:00',
    turnaround_days: null,
    messages: [
      {
        id: 'message-1',
        audience: 'internal',
        is_visible_to_client: false,
        body: 'This client always asks for this and never approves the creative.',
        author_name: 'Mohammed',
        is_from_client: false,
        created_at: '2026-10-04T09:00:00+00:00',
      },
      {
        id: 'message-2',
        audience: 'client',
        is_visible_to_client: true,
        body: 'We can do that — paused from the 28th.',
        author_name: 'Mohammed',
        is_from_client: false,
        created_at: '2026-10-04T10:00:00+00:00',
      },
    ],
    tasks: [
      {
        id: 'task-1',
        title: 'Check whether pausing loses the learning phase',
        state: 'todo',
        state_label: 'To do',
        assignee_name: 'Reem',
      },
    ],
    attachments: [
      {
        id: 'file-1',
        audience: 'internal',
        is_visible_to_client: false,
        name: 'competitor-rates.csv',
        type: 'text/csv',
        type_label: 'CSV',
        is_image: false,
        byte_size: 2048,
        uploaded_by_name: 'Mohammed',
        is_from_client: false,
        uploaded_at: '2026-10-04T09:30:00+00:00',
      },
      {
        id: 'file-2',
        audience: 'client',
        is_visible_to_client: true,
        name: 'screenshot.png',
        type: 'image/png',
        type_label: 'Image',
        is_image: true,
        byte_size: 1_572_864,
        uploaded_by_name: 'Reem',
        is_from_client: true,
        uploaded_at: '2026-10-04T10:30:00+00:00',
      },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = [
    'client_requests.submit',
    'client_requests.view',
    'client_requests.triage',
  ];
  state.vocabulary = vocabulary();
  state.queue = queue();
  state.request = request();
  state.triaged = null;
  state.answered = null;
  state.attached = null;
  state.attachFailsWith = null;
  state.promised = null;
  state.raised = null;
  state.submitted = null;
  state.assigned = null;
  state.owners = [
    { id: 'user-9', name: 'Mohammed' },
    { id: 'user-4', name: 'Noura Al-Shehri' },
  ];
});

describe('the intake queue', function () {
  it('publishes who the queue is waiting on, both ways', async () => {
    await renderWithProviders(<RequestsPage />);

    const summary = await screen.findByRole('region', { name: 'Intake summary' });

    /*
     * The pair the module exists for. A single "open" figure would let a conversation stalled on the client hide
     * behind work the agency has not started, and only one of those is the agency's to fix.
     */
    expect(within(summary).getByText('Waiting on us')).toBeInTheDocument();
    expect(within(summary).getByText('Waiting on the client')).toBeInTheDocument();
    expect(within(summary).getByText('We asked them something')).toBeInTheDocument();
  });

  it('publishes accepted work with no promised date', async () => {
    await renderWithProviders(<RequestsPage />);

    const summary = await screen.findByRole('region', { name: 'Intake summary' });

    // "We are on it" is the whole of what those clients were told.
    expect(within(summary).getByText('Accepted, no date promised')).toBeInTheDocument();
    expect(within(summary).getByText('"We are on it" is all they were told')).toBeInTheDocument();
  });

  it('says a client has been ignored rather than that the queue is busy', async () => {
    await renderWithProviders(<RequestsPage />);

    /*
     * Above the chart and outside its frame, so it survives the switch to the table. Five days against a stated
     * patience of two is somebody who has not heard back, which is a different statement from a busy queue.
     */
    expect(
      await screen.findByText(/That is not a busy queue — it is somebody who has not heard back/),
    ).toBeInTheDocument();
  });

  it('reports no rates rather than nought percent before anything closes', async () => {
    state.queue = queue({
      load: load({ delivery_rate: null, promised_share: null, delivered: 0, declined: 0 }),
    });

    await renderWithProviders(<RequestsPage />);

    expect(await screen.findByText(/no delivery rate to report/)).toBeInTheDocument();
    expect(screen.getByText(/no promised share to report/)).toBeInTheDocument();
  });

  it("labels a client's stated urgency as theirs on every card", async () => {
    await renderWithProviders(<RequestsPage />);

    const board = await screen.findByRole('region', { name: 'The intake queue' });

    /*
     * Never a bare priority badge. A client who could set the agency's priorities by typing would set all of
     * them, so the card says whose word it is.
     */
    expect(within(board).getByText('Client said: Urgent')).toBeInTheDocument();
  });

  it('says which cards carry no promised date, and how long one has gone unanswered', async () => {
    await renderWithProviders(<RequestsPage />);

    const board = await screen.findByRole('region', { name: 'The intake queue' });

    expect(within(board).getByText('No date promised')).toBeInTheDocument();
    expect(within(board).getByText('Unanswered for 5 days')).toBeInTheDocument();
  });

  it("shows a column's whole total, not the number of cards drawn", async () => {
    await renderWithProviders(<RequestsPage />);

    const board = await screen.findByRole('region', { name: 'The intake queue' });
    const heading = within(board).getByRole('heading', { level: 2, name: /New/ });

    expect(heading).toHaveTextContent('2');
    expect(within(board).getAllByRole('button')).toHaveLength(1);
  });

  it('keeps who each stage waits on out of the colour and in the table', async () => {
    await renderWithProviders(<RequestsPage />);

    const frame = await screen.findByRole('region', {
      name: 'Where the open requests are sitting',
    });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    const waiting = within(frame).getByRole('row', { name: /Waiting on the client/ });

    expect(within(waiting).getByText('The client')).toBeInTheDocument();
    expect(within(frame).getByRole('row', { name: /^New/ })).toBeInTheDocument();
  });

  it("says the urgency chart is the client's word and not a priority", async () => {
    await renderWithProviders(<RequestsPage />);

    const frame = await screen.findByRole('region', {
      name: 'How urgent the clients said it was',
    });

    expect(within(frame).getByText(/not how we have prioritised the work/)).toBeInTheDocument();
  });
});

describe('a request', function () {
  it('marks every message with who can see it', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Pause the retargeting over Eid/ }),
    );

    /*
     * Scoped to the thread rather than the whole conversation section: the reply form below it offers the same two
     * audience labels as choices, and a query that matched both would be asserting the form rather than the badges.
     *
     * A thread where internal and client-visible messages look alike is a thread somebody replies to wrongly.
     */
    const thread = await screen.findByRole('list', { name: 'Messages on this request' });

    expect(within(thread).getByText('Internal only')).toBeInTheDocument();
    expect(within(thread).getByText('The client sees this')).toBeInTheDocument();
  });

  it('will not send a reply until the audience has been chosen', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Pause the retargeting over Eid/ }),
    );

    await userEvent.type(await screen.findByLabelText('Reply'), 'Confirmed.');

    /*
     * No default. An internal note reaching a client ends a relationship, and a default is exactly how it
     * happens — so the button says what it would do and stays disabled until it can say it truthfully.
     */
    const send = screen.getByRole('button', { name: 'Choose who sees this first' });

    expect(send).toBeDisabled();
    expect(state.answered).toBeNull();

    await userEvent.click(screen.getByRole('button', { name: 'The client will see this' }));
    await userEvent.click(screen.getByRole('button', { name: 'Send to the client' }));

    expect(state.answered).toMatchObject({ audience: 'client', body: 'Confirmed.' });
  });

  it('asks for the reason a client will read before declining', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Pause the retargeting over Eid/ }),
    );

    const moves = await screen.findByRole('region', { name: 'Where it can go' });
    await userEvent.click(within(moves).getByRole('button', { name: 'Declined' }));

    expect(state.triaged).toBeNull();
    expect(await screen.findByLabelText(/Why it is being declined/)).toBeInTheDocument();

    await userEvent.type(
      screen.getByLabelText(/Why it is being declined/),
      'Out of scope for this engagement.',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Save and move' }));

    expect(state.triaged).toMatchObject({
      state: 'declined',
      reason: 'Out of scope for this engagement.',
    });
  });

  it('says no date has been promised rather than leaving a blank', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Pause the retargeting over Eid/ }),
    );

    /*
     * Scoped to the detail's own dates, because the card in the column says the same thing — which is correct, and
     * is why the query has to say which one it means.
     *
     * A blank reads as a date somebody forgot to load; this is the fact.
     */
    const dates = await screen.findByRole('region', { name: 'Dates on this request' });

    expect(within(dates).getByText('No date promised')).toBeInTheDocument();
  });

  it('lets a promise be withdrawn, not only made', async () => {
    state.request = request({ promised_at: '2026-10-16', promised_by_name: 'Mohammed' });

    await renderWithProviders(<RequestsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Pause the retargeting over Eid/ }),
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Withdraw the promise' }));

    expect(state.promised).toMatchObject({ promisedAt: '' });
  });

  it('raises a task without turning the request into one', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Pause the retargeting over Eid/ }),
    );

    const work = await screen.findByRole('region', { name: 'The work on it' });

    // The task already linked is listed, and the request is still a request.
    expect(
      within(work).getByText('Check whether pausing loses the learning phase'),
    ).toBeInTheDocument();

    await userEvent.type(within(work).getByLabelText('Raise a task for this'), 'Check the pixel');
    await userEvent.click(within(work).getByRole('button', { name: 'Add to the board' }));

    expect(state.raised).toMatchObject({ title: 'Check the pixel' });
  });

  it('offers no task for a question, and says why', async () => {
    state.request = request({
      kind: 'question',
      kind_label: 'A question',
      title: 'Why did the cost per purchase jump?',
      tasks: [],
      attachments: [],
    });
    state.queue = queue({
      columns: [
        column({
          requests: [
            card({
              kind: 'question',
              kind_label: 'A question',
              title: 'Why did the cost per purchase jump?',
            }),
          ],
        }),
      ],
    });

    await renderWithProviders(<RequestsPage />);

    await userEvent.click(
      await screen.findByRole('button', { name: /Why did the cost per purchase jump\?/ }),
    );

    /*
     * Said rather than refused silently. A board filled with cards whose completion is a sentence in a reply is a
     * board nobody trusts.
     */
    expect(
      await screen.findByText(/A question needs an answer rather than a task/),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText('Raise a task for this')).not.toBeInTheDocument();
  });
});

describe('files on a request', function () {
  it('marks every file with who can see it, and links it as a download', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting/ }));

    const files = await screen.findByRole('list', { name: 'Files' });

    /*
     * The same badge a message carries, for a worse leak: an internal note is a sentence, and an internal
     * attachment is a competitor's rate card. A list where the two look alike is a list somebody forwards.
     */
    expect(within(files).getByText('competitor-rates.csv')).toBeInTheDocument();
    expect(within(files).getByText('Internal only')).toBeInTheDocument();

    /* And it is a link, so the browser handles a response the server marks as an attachment. */
    const link = within(files).getByRole('link', { name: 'screenshot.png' });
    expect(link).toHaveAttribute('href', '/api/client-request-attachments/file-2');

    /* The size in units a file manager would show, and the type the bytes reported. */
    expect(within(files).getByText(/1\.5 MB/)).toBeInTheDocument();
    expect(within(files).getByText(/Image/)).toBeInTheDocument();
  });

  it('will not upload until the agency has chosen who sees it', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting/ }));

    const input = await screen.findByLabelText('Add a file');

    /*
     * No default audience, exactly as for a message — and here the consequence is worse. The control is disabled
     * rather than defaulting to internal, because a default is how an internal screenshot reaches the client it
     * is about.
     */
    expect(input).toBeDisabled();
    expect(screen.getByText('Choose who sees it first.')).toBeInTheDocument();
  });

  it('sends the file with the audience that was chosen', async () => {
    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'The client will see this' }));

    const input = await screen.findByLabelText('Add a file');
    const file = new File(['a,b\n1,2\n'], 'lines.csv', { type: 'text/csv' });

    await userEvent.upload(input, file);

    expect(state.attached).toMatchObject({ requestId: 'request-1', audience: 'client' });
  });

  it("shows the server's own refusal, which says what to send instead", async () => {
    const { ApiError } = await import('@/shared/api/client');

    state.attachFailsWith = new ApiError(
      'That file is 7.2 MB and the limit is 5.0 MB. Send a smaller version, or a link to it.',
      422,
    );

    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'The client will see this' }));

    const input = await screen.findByLabelText('Add a file');
    await userEvent.upload(input, new File(['x'], 'big.png', { type: 'image/png' }));

    /*
     * Beside the control rather than in the page's shared banner: every refusal here names the thing to do
     * instead, and that sentence is useless a long way from the file picker.
     */
    expect(await screen.findByText(/Send a smaller version/)).toBeInTheDocument();
  });

  it('offers no upload on a settled request', async () => {
    state.request = request({ state: 'declined', state_label: 'Declined' });

    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting/ }));

    await screen.findByRole('list', { name: 'Files' });

    // The thread is finished. Adding to it is how a second piece of work ends up with no record of being asked.
    expect(screen.queryByLabelText('Add a file')).not.toBeInTheDocument();
  });

  it('offers a client the upload with no audience to choose', async () => {
    permissions.current = ['client_requests.submit'];
    state.queue = queue({ is_agency_view: false, can_triage: false });
    state.request = request({
      submitted_by_name: null,
      owner_id: null,
      owner_name: null,
      tasks: [],
      messages: [],
      attachments: [],
    });

    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Pause the retargeting/ }));

    const input = await screen.findByLabelText('Add a file');

    /*
     * Theirs always goes to the client audience — the server forces it, and a CHECK refuses anything else — so
     * there is nothing here for them to get wrong.
     */
    expect(input).toBeEnabled();
    expect(screen.queryByText('Choose who sees it first.')).not.toBeInTheDocument();

    await userEvent.upload(input, new File(['x'], 'mine.png', { type: 'image/png' }));

    expect(state.attached).toMatchObject({ audience: 'client' });
  });
});

describe("a client's own view", function () {
  it('offers no audience choice, no triage and no internal figures', async () => {
    permissions.current = ['client_requests.submit'];
    state.queue = queue({ is_agency_view: false, can_triage: false });
    state.request = request({
      // What the server actually returns to a client: no internal note, no owner, no tasks.
      submitted_by_name: null,
      owner_id: null,
      owner_name: null,
      tasks: [],
      messages: [
        {
          id: 'message-2',
          audience: 'client',
          is_visible_to_client: true,
          body: 'We can do that — paused from the 28th.',
          author_name: 'Mohammed',
          is_from_client: false,
          created_at: '2026-10-04T10:00:00+00:00',
        },
      ],
    });

    await renderWithProviders(<RequestsPage />);

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Your requests' }),
    ).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Pause the retargeting over Eid/ }));

    await screen.findByRole('region', { name: 'The conversation' });

    /*
     * A surface that did not know which side it was showing would offer an "internal note" control that silently
     * produced a client-visible message. It also must not show the agency's own figures or plan of work.
     */
    expect(screen.queryByText('Who sees this')).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Where it can go' })).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'The work on it' })).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Intake summary' })).not.toBeInTheDocument();
    expect(screen.queryByText('Internal only')).not.toBeInTheDocument();
  });

  it('offers a client the form to ask for something', async () => {
    permissions.current = ['client_requests.submit'];
    state.queue = queue({ is_agency_view: false, can_triage: false });

    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Ask us for something' }));

    /*
     * The one write a client has in this product, and the reason the module exists: an intake queue only agency
     * staff can write to is just another task list.
     */
    const form = await screen.findByRole('heading', { name: 'New request' });

    expect(form).toBeInTheDocument();
    // Their own client is locked, because they have exactly one.
    expect(screen.queryByText('Client', { selector: 'legend' })).not.toBeInTheDocument();
  });

  it("tells a client their stated urgency does not reorder anybody's work", async () => {
    permissions.current = ['client_requests.submit'];
    state.queue = queue({ is_agency_view: false, can_triage: false });

    await renderWithProviders(<RequestsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Ask us for something' }));

    /*
     * Said on the form rather than discovered later. A field a client expects to act as a queue-jump, which does
     * not, is worse than no field at all.
     */
    expect(
      await screen.findByText(/it does not reorder anybody's work on its own/),
    ).toBeInTheDocument();
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<RequestsPage />);

    expect(await screen.findByText('Requests are not part of your access')).toBeInTheDocument();
  });
});

describe('who a client’s question is given to', () => {
  async function openRequest() {
    await renderWithProviders(<RequestsPage />);
    await userEvent.click(
      await screen.findByRole('button', { name: /Pause the retargeting over Eid/ }),
    );
  }

  /*
   * The queue displayed an owner and offered no way to set one, so every request belonged to nobody — and the
   * figure this module leads with is how long a client has been waiting for somebody to pick theirs up.
   */
  it('offers the people the server says can open the request', async () => {
    await openRequest();

    const control = await screen.findByRole('combobox', { name: 'Owner' });

    expect(within(control).getByRole('option', { name: 'Noura Al-Shehri' })).toBeInTheDocument();

    await userEvent.selectOptions(control, 'user-4');

    /*
     * `waitFor`, because a `change` event flushes fewer microtasks than a click and the mutation runs in one.
     * Asserting straight after the selection reads the state before TanStack has called the request at all.
     */
    /*
     * `waitFor`, because a `change` event flushes fewer microtasks than a click does and the mutation runs in
     * one. Asserting straight after the selection reads the state before the request has been made.
     */
    await waitFor(() =>
      expect(state.assigned).toEqual({ requestId: 'request-1', ownerId: 'user-4' }),
    );
  });

  it('shows the current owner as the selected option', async () => {
    await openRequest();

    expect(await screen.findByRole('combobox', { name: 'Owner' })).toHaveValue('user-9');
  });

  /* Taking it back off somebody is a choice rather than an absence. */
  it('offers nobody as an option', async () => {
    await openRequest();

    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Owner' }), '');

    await waitFor(() => expect(state.assigned).toEqual({ requestId: 'request-1', ownerId: null }));
  });

  /*
   * A client reading their own thread gets no staff control at all — not a disabled one. Who inside the agency
   * is handling a question is internal, and the portal's own resources exclude it in SQL.
   */
  it('gives a client no assignment control and asks the server for no candidates', async () => {
    state.queue = queue({ is_agency_view: false, can_triage: false });

    await openRequest();

    expect(screen.queryByRole('combobox', { name: 'Owner' })).not.toBeInTheDocument();
  });
});
