import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { CreativeHubPage } from './CreativeHubPage';
import type {
  CreativeBoard,
  CreativeRequest,
  CreativeVersion,
  CreativeVocabulary,
  RecordedSpec,
  SpecReport,
} from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Noon Retail' }] },
  }),
}));

const permissions = vi.hoisted(() => ({
  current: ['creative.view', 'creative.manage', 'creative.approve'] as string[],
}));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: {
    creativeView: 'creative.view',
    creativeManage: 'creative.manage',
    creativeApprove: 'creative.approve',
  },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  vocabulary: null as CreativeVocabulary | null,
  board: null as CreativeBoard | null,
  request: null as CreativeRequest | null,
  specs: [] as RecordedSpec[],
  decided: null as Record<string, unknown> | null,
  commented: null as Record<string, unknown> | null,
  moved: null as Record<string, unknown> | null,
  assigned: null as Record<string, unknown> | null,
  owners: [] as { id: string; name: string }[],
}));

vi.mock('./api', () => ({
  fetchVocabulary: () => Promise.resolve(state.vocabulary),
  fetchBoard: () => Promise.resolve(state.board),
  fetchRequest: () => Promise.resolve(state.request),
  fetchRecordedSpecs: () => Promise.resolve(state.specs),
  fetchCreativeOwners: () => Promise.resolve(state.owners),
  moveRequest: (input: Record<string, unknown>) => {
    state.moved = input;

    return Promise.resolve(state.request);
  },
  assignCreativeOwner: (input: Record<string, unknown>) => {
    state.assigned = input;

    return Promise.resolve(state.request);
  },
  decideOnVersion: (input: Record<string, unknown>) => {
    state.decided = input;

    return Promise.resolve(state.request);
  },
  commentOnRequest: (input: Record<string, unknown>) => {
    state.commented = input;

    return Promise.resolve(state.request);
  },
}));

function vocabulary(): CreativeVocabulary {
  return {
    states: [
      {
        state: 'draft',
        label: 'Draft',
        position: 0,
        is_open: true,
        is_waiting_on_review: false,
        allowed_next: ['briefed', 'cancelled'],
        requires_re_review: false,
      },
      /*
       * Every state a move control can offer, because the label comes from this list: the server publishes all
       * of them, and a fixture that named only two left the buttons falling back to raw values like `briefed`.
       */
      {
        state: 'briefed',
        label: 'Briefed',
        position: 1,
        is_open: true,
        is_waiting_on_review: false,
        allowed_next: ['in_production', 'cancelled'],
        requires_re_review: false,
      },
      {
        state: 'approved',
        label: 'Approved',
        position: 5,
        is_open: false,
        is_waiting_on_review: false,
        allowed_next: ['in_production', 'cancelled'],
        requires_re_review: true,
      },
      {
        state: 'cancelled',
        label: 'Cancelled',
        position: 6,
        is_open: false,
        is_waiting_on_review: false,
        allowed_next: ['draft'],
        requires_re_review: false,
      },
    ],
    kinds: [{ kind: 'image', label: 'Image', measurable: ['width', 'height'] }],
    visibilities: [
      { visibility: 'internal', label: 'Internal note', is_visible_to_client: false },
      { visibility: 'client', label: 'Visible to the client', is_visible_to_client: true },
    ],
  };
}

function board(overrides: Partial<CreativeBoard> = {}): CreativeBoard {
  return {
    columns: [
      {
        state: 'draft',
        label: 'Draft',
        requests: [
          {
            id: 'request-1',
            client_id: 'client-1',
            client_name: 'Noon Retail',
            project_id: null,
            project_name: null,
            title: 'Ramadan hero image',
            state: 'draft',
            state_label: 'Draft',
            kind: 'image',
            kind_label: 'Image',
            provider: null,
            placement: null,
            owner_id: null,
            owner_name: null,
            due_at: '2026-10-20T00:00:00+00:00',
            days_until_due: 13,
            is_overdue: false,
            cancelled_reason: null,
            state_changed_at: null,
            created_at: '2026-10-01T00:00:00+00:00',
            version_count: 0,
            preview: null,
          },
        ],
      },
      { state: 'in_review', label: 'In review', requests: [] },
      { state: 'approved', label: 'Approved', requests: [] },
      { state: 'cancelled', label: 'Cancelled', requests: [] },
    ],
    open: 1,
    waiting_on_review: 0,
    overdue: 0,
    ...overrides,
  };
}

function spec(overrides: Partial<SpecReport> = {}): SpecReport {
  return {
    verdict: 'pass',
    verdict_label: 'Within spec',
    blocks_publishing: false,
    provider: 'snapchat',
    placement: 'story_ad_preview_image',
    checks: [
      {
        requirement: 'width',
        requirement_label: 'Width',
        verdict: 'pass',
        verdict_label: 'Within spec',
        measured: '360',
        expected: 'at least 360 px',
        detail: null,
      },
    ],
    source: {
      url: 'https://developers.snap.com/api/marketing-api/Ads-API/creatives',
      documented_at: '2026-10-07',
      recorded_by: 'Read from the Snapchat docs',
      note: null,
      fresh_days: 182,
    },
    spec_age_days: 0,
    ...overrides,
  };
}

function version(overrides: Partial<CreativeVersion> = {}): CreativeVersion {
  return {
    id: 'version-1',
    number: 1,
    kind: 'image',
    label: 'Cut 1',
    storage_path: 'creative/v1.png',
    body: null,
    uploaded_by_name: 'Layla',
    created_at: '2026-10-02T00:00:00+00:00',
    measurements: {
      width_px: 360,
      height_px: 600,
      aspect_ratio: '3:5',
      file_size_bytes: 500_000,
      duration_seconds: null,
      format: 'png',
      text_length: null,
      unmeasured_reason: null,
    },
    spec: spec(),
    decision: null,
    decision_label: null,
    decision_reason: null,
    decided_by_name: null,
    decided_at: null,
    is_approved: false,
    ...overrides,
  };
}

function request(overrides: Partial<CreativeRequest> = {}): CreativeRequest {
  return {
    ...board().columns[0]!.requests[0]!,
    provider: 'snapchat',
    placement: 'story_ad_preview_image',
    brief: 'A hero image for the Ramadan push.',
    versions: [version()],
    comments: [],
    newest_is_approved: false,
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['creative.view', 'creative.manage', 'creative.approve'];
  state.vocabulary = vocabulary();
  state.board = board();
  state.request = request();
  state.specs = [
    {
      provider: 'snapchat',
      placement: 'story_ad_preview_image',
      kind: 'image',
      requirements: ['width', 'height'],
      source: {
        url: 'https://developers.snap.com/api/marketing-api/Ads-API/creatives',
        documented_at: '2026-10-07',
        recorded_by: null,
        note: null,
        fresh_days: 182,
      },
      is_stale: false,
      age_days: 0,
    },
  ];
  state.decided = null;
  state.commented = null;
  state.moved = null;
  state.assigned = null;
  state.owners = [{ id: 'user-5', name: 'Demo Creative' }];
});

describe('the creative hub', () => {
  it('says which placements it can check, and why the list is short', async () => {
    await renderWithProviders(<CreativeHubPage />);

    /*
     * The absent list is the one that matters: those are the placements an agency's work goes out on without a
     * safety net, and the reason the recorded list is short is a fact about the platforms rather than a gap
     * somebody forgot to fill.
     */
    expect(
      await screen.findByText(
        /do not publish their creative requirements in a form a program can read/,
      ),
    ).toBeInTheDocument();
    expect(screen.getByText(/snapchat · story_ad_preview_image/)).toBeInTheDocument();
  });

  it('shows the checks with both numbers', async () => {
    await renderWithProviders(<CreativeHubPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));

    // "360 · at least 360 px" is something a producer acts on. "Width: pass" is not.
    expect(await screen.findByText('360 · at least 360 px')).toBeInTheDocument();
    expect(screen.getByText(/Read from the platform's own page on 2026-10-07/)).toBeInTheDocument();
  });

  it('says nothing was checked when no specification is recorded', async () => {
    state.request = request({
      versions: [
        version({
          spec: spec({
            verdict: 'no_spec',
            verdict_label: 'No spec recorded',
            checks: [],
            source: null,
            spec_age_days: null,
            provider: 'meta',
            placement: 'reels',
          }),
        }),
      ],
    });

    await renderWithProviders(<CreativeHubPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));

    /*
     * Presented as a gap in this product rather than a problem with the file, because it is — and because a
     * reader who thinks the file failed will go and change a file that was fine.
     */
    expect(
      await screen.findByText(/That is a gap in this product, not a problem with the file/),
    ).toBeInTheDocument();
  });

  it('shows an unmeasured property as not measured rather than as zero', async () => {
    state.request = request({
      versions: [
        version({
          measurements: {
            width_px: null,
            height_px: null,
            aspect_ratio: null,
            file_size_bytes: 500_000,
            duration_seconds: null,
            format: 'png',
            text_length: null,
            unmeasured_reason: 'The file could not be opened.',
          },
          spec: spec({
            verdict: 'unchecked',
            verdict_label: 'Not checked',
            checks: [
              {
                requirement: 'width',
                requirement_label: 'Width',
                verdict: 'unchecked',
                verdict_label: 'Not checked',
                measured: null,
                expected: 'at least 360 px',
                detail: 'The file could not be opened.',
              },
            ],
          }),
        }),
      ],
    });

    await renderWithProviders(<CreativeHubPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));

    // A width this product could not read is unknown; a zero would be rendered as a very small image.
    expect((await screen.findAllByText(/Not measured/)).length).toBeGreaterThan(0);
    expect(screen.getByText('The file could not be opened.')).toBeInTheDocument();
  });

  it('warns when an approval no longer covers the newest version', async () => {
    state.request = request({
      state: 'approved',
      state_label: 'Approved',
      newest_is_approved: false,
      versions: [
        version({ decision: 'approved', decision_label: 'Approved', is_approved: true }),
        version({ id: 'version-2', number: 2 }),
      ],
    });

    await renderWithProviders(<CreativeHubPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));

    /*
     * The one thing an approval trail must never let somebody believe. A request reading "approved" with an
     * undecided newer file is stale, and the person about to tell a client it is signed off needs to know.
     */
    expect(
      await screen.findByText(/do not tell the client the latest cut is signed off/),
    ).toBeInTheDocument();
  });

  it('asks what to change before sending work back', async () => {
    await renderWithProviders(<CreativeHubPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));

    await userEvent.click(await screen.findByRole('button', { name: 'Request changes' }));
    await userEvent.type(
      await screen.findByLabelText(/What needs to change/),
      'The logo is cropped.',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Send it back' }));

    // The note is the only record of what the reviewer objected to; the next version is a guess without it.
    expect(state.decided).toEqual({
      versionId: 'version-1',
      outcome: 'changes_requested',
      reason: 'The logo is cropped.',
    });
  });

  it('offers no decision to somebody who may not approve', async () => {
    permissions.current = ['creative.view', 'creative.manage'];

    await renderWithProviders(<CreativeHubPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));

    /*
     * A creative uploads all day and signs nothing off. Hiding the control is a courtesy; the server refuses
     * the request regardless.
     */
    expect(screen.queryByRole('button', { name: 'Approve this version' })).not.toBeInTheDocument();
  });

  it('makes the comment audience an explicit choice', async () => {
    await renderWithProviders(<CreativeHubPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));

    await userEvent.type(await screen.findByLabelText('Add a comment'), 'Looks right to me.');
    await userEvent.click(screen.getByRole('button', { name: 'Visible to the client' }));
    await userEvent.click(screen.getByRole('button', { name: 'Post' }));

    expect(state.commented).toEqual({
      requestId: 'request-1',
      body: 'Looks right to me.',
      visibility: 'client',
    });
  });

  it('marks a card with no placement, because nothing can be checked against one', async () => {
    await renderWithProviders(<CreativeHubPage />);

    const card = await screen.findByRole('button', { name: /Ramadan hero image/ });

    expect(within(card).getByText('No placement')).toBeInTheDocument();
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<CreativeHubPage />);

    expect(
      await screen.findByText('The Creative Hub is not part of your access'),
    ).toBeInTheDocument();
  });
});

describe('moving work and handing it over', () => {
  async function openBrief() {
    await renderWithProviders(<CreativeHubPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Ramadan hero image/ }));
  }

  /*
   * The moves offered are the server's. `allowed_next` comes from the API, so a target the board would refuse
   * is never on screen — the alternative, offering every state and showing an error, teaches people to distrust
   * the interface.
   */
  it('offers only the moves the server allows from here', async () => {
    await openBrief();

    expect(await screen.findByRole('button', { name: 'Briefed' })).toBeInTheDocument();

    /* "Approved" is not reachable from a draft, so it is absent rather than disabled. */
    expect(screen.queryByRole('button', { name: 'Approved' })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Briefed' }));

    await waitFor(() => expect(state.moved).toEqual({ requestId: 'request-1', state: 'briefed' }));
  });

  /*
   * Cancelling asks for its reason before the request rather than after a refusal, and that reason is the only
   * record of why the work stopped.
   */
  it('asks why before cancelling', async () => {
    await openBrief();

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelled' }));

    expect(state.moved).toBeNull();

    await userEvent.type(
      screen.getByLabelText(/Why it is being cancelled/),
      'The client pulled the campaign.',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Cancel this work' }));

    await waitFor(() =>
      expect(state.moved).toEqual({
        requestId: 'request-1',
        state: 'cancelled',
        reason: 'The client pulled the campaign.',
      }),
    );
  });

  /*
   * `creative_requests.owner_id` could be written when a brief was created and never afterwards, so work could
   * not be handed to anybody.
   */
  it('hands the brief to somebody the server says can open it', async () => {
    await openBrief();

    const control = await screen.findByRole('combobox', { name: 'Who is making it' });

    expect(within(control).getByRole('option', { name: 'Demo Creative' })).toBeInTheDocument();

    await userEvent.selectOptions(control, 'user-5');

    await waitFor(() =>
      expect(state.assigned).toEqual({ requestId: 'request-1', ownerId: 'user-5' }),
    );
  });

  it('offers neither control to somebody who may only read the board', async () => {
    permissions.current = ['creative.view'];

    await openBrief();

    expect(screen.queryByRole('combobox', { name: 'Who is making it' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Briefed' })).not.toBeInTheDocument();
  });
});
