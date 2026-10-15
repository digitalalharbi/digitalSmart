import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { LeadCentrePage } from './LeadCentrePage';
import type {
  Journey,
  Lead,
  LeadCentre,
  LeadDuplicate,
  LeadFunnel,
  LeadHistoryEntry,
  LeadOwnerCandidate,
} from './types';

/*
 * ECharts needs a real canvas and a measured container, neither of which jsdom has. The
 * chart's own behaviour is the chart component's business; here we only need to know the page
 * asked for one — and the table twin, which this page's figures are also reachable through,
 * is real DOM and is asserted directly.
 */
vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

vi.mock('@/features/integrations', () => ({
  useProvidersQuery: () => ({ isPending: false, data: state.crmProviders }),
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Noon Retail' }] },
  }),
}));

vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({ isPending: false, isFetching: false, data: { data: [] } }),
}));

const permissions = vi.hoisted(() => ({ current: ['leads.view', 'leads.manage'] as string[] }));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: {
    leadsView: 'leads.view',
    leadsManage: 'leads.manage',
  },
  useSession: () => ({
    can: (permission: string) => permissions.current.includes(permission),
  }),
}));

const state = vi.hoisted(() => ({
  centre: null as LeadCentre | null,
  leads: [] as Lead[],
  journey: null as Journey | null,
  duplicates: [] as LeadDuplicate[],
  owners: [] as LeadOwnerCandidate[],
  history: [] as LeadHistoryEntry[],
  crmRead: null as string | null,
  crmProviders: [] as {
    provider: string;
    label: string;
    category: string;
    connection_id: string | null;
  }[],
  assigned: null as { leadId: string; ownerId: string | null } | null,
  valued: null as { leadId: string; valueMinor: number | null; currency: string | null } | null,
  noted: null as { leadId: string; note: string } | null,
  moved: null as { leadId: string; stage: string; reason?: string } | null,
  merged: null as { leadId: string; otherLeadId: string; stage: string; note?: string } | null,
}));

vi.mock('./api', () => ({
  fetchLeadCentre: () => Promise.resolve(state.centre),
  fetchLeads: () => Promise.resolve(state.leads),
  /*
   * Rejects rather than resolving null, which is what the server does: `GetJourney` answers 404 for a lead it
   * cannot build a journey for. A mock that resolved null would hand the panel a value its type says cannot
   * arrive, and the test would be asserting against a shape production never sees.
   */
  fetchJourney: () =>
    state.journey === null
      ? Promise.reject(new Error('Not found'))
      : Promise.resolve(state.journey),
  changeStage: (change: { leadId: string; stage: string; reason?: string }) => {
    state.moved = change;

    return Promise.resolve(state.leads[0]);
  },
  fetchLeadDuplicates: () => Promise.resolve(state.duplicates),
  fetchLeadOwners: () => Promise.resolve(state.owners),
  fetchLeadHistory: () => Promise.resolve(state.history),
  syncCrmDeals: (clientId: string) => {
    state.crmRead = clientId;

    return Promise.resolve({
      read: 3,
      recorded: 3,
      left_alone: 1,
      source: 'sandbox',
      source_label: 'Sandbox',
    });
  },
  assignLead: (assignment: { leadId: string; ownerId: string | null }) => {
    state.assigned = assignment;

    return Promise.resolve(state.leads[0]);
  },
  setLeadValue: (value: { leadId: string; valueMinor: number | null; currency: string | null }) => {
    state.valued = value;

    return Promise.resolve(state.leads[0]);
  },
  addLeadNote: (note: { leadId: string; note: string }) => {
    state.noted = note;

    return Promise.resolve(state.leads[0]);
  },
  mergeLeads: (merge: { leadId: string; otherLeadId: string; stage: string; note?: string }) => {
    state.merged = merge;

    return Promise.resolve(state.leads[0]);
  },
}));

function duplicate(overrides: Partial<LeadDuplicate> = {}): LeadDuplicate {
  return {
    id: 'lead-2',
    source_kind: 'whatsapp_conversation',
    source_kind_label: 'WhatsApp conversation',
    source_detail: 'Sales line',
    stage: 'contacted',
    stage_label: 'Contacted',
    name: 'Layla Al-Harbi',
    email: null,
    phone: '966555001122',
    received_at: '2026-09-02T09:00:00+00:00',
    matched_on: 'phone',
    matched_on_label: 'Same phone number',
    ...overrides,
  };
}

function funnel(overrides: Partial<LeadFunnel> = {}): LeadFunnel {
  return {
    total: 40,
    contacted: 8,
    reached: 20,
    qualified: 6,
    disqualified: 2,
    won: 5,
    lost: 3,
    open: 30,
    closed: 10,
    won_value_minor: 500_000,
    open_value_minor: 0,
    currency: 'SAR',
    currency_count: 1,
    is_value_reportable: true,
    win_rate: 50,
    qualification_rate: 30,
    contact_rate: 70,
    average_won_value_minor: 100_000,
    ...overrides,
  };
}

function centre(overrides: Partial<LeadCentre> = {}): LeadCentre {
  return {
    window_days: 30,
    funnel: funnel(),
    by_source: [
      {
        source_kind: 'form_submission',
        source_kind_label: 'Form submission',
        source_detail: 'Contact form',
        is_measured: true,
        total: 30,
        qualified: 5,
        won: 4,
        closed: 8,
        won_value_minor: 400_000,
        currency: 'SAR',
        win_rate: 50,
      },
      {
        source_kind: 'manual',
        source_kind_label: 'Entered by hand',
        source_detail: null,
        is_measured: false,
        total: 10,
        qualified: 1,
        won: 1,
        closed: 2,
        won_value_minor: 100_000,
        currency: 'SAR',
        win_rate: 50,
      },
    ],
    by_campaign: [
      {
        label: 'autumn-range',
        total: 25,
        qualified: 4,
        won: 3,
        closed: 6,
        evidence: 'utm_parameters',
        evidence_label: 'UTM parameters',
        confidence: 80,
        is_unattributed: false,
        win_rate: 50,
      },
      {
        label: 'Not attributed',
        total: 15,
        qualified: 2,
        won: 2,
        closed: 4,
        evidence: null,
        evidence_label: null,
        confidence: null,
        is_unattributed: true,
        win_rate: 50,
      },
    ],
    signals: { whatsapp_click: 420, form_start: 95 },
    signals_note: 'Counted taps and form starts. Not leads, and not comparable as a ratio.',
    ...overrides,
  };
}

/** The client picker is a search box over the server's top few, not a row of every client. */
async function chooseClient(term: string): Promise<void> {
  await userEvent.type(await screen.findByRole('combobox', { name: 'Client' }), term);
  await userEvent.click(await screen.findByRole('option', { name: 'Noon Retail' }));
}

function lead(overrides: Partial<Lead> = {}): Lead {
  return {
    id: 'lead-1',
    client_id: 'client-1',
    client_name: 'Noon Retail',
    project_id: null,
    source_kind: 'form_submission',
    source_kind_label: 'Form submission',
    source_detail: 'Contact form',
    is_measured: true,
    stage: 'new',
    stage_label: 'New',
    is_closed: false,
    disqualified_reason: null,
    owner_id: null,
    owner_name: null,
    name: 'Layla Al-Harbi',
    email: null,
    phone: '966555001122',
    message: null,
    value_minor: 250_000,
    currency: 'SAR',
    evidence: 'utm_parameters',
    evidence_label: 'UTM parameters',
    confidence: 80,
    utm_source: 'meta',
    utm_campaign: 'autumn-range',
    received_at: '2026-10-01T09:00:00+00:00',
    first_contacted_at: null,
    qualified_at: null,
    closed_at: null,
    hours_to_first_contact: null,
    merged_into_id: null,
    merged_at: null,
    absorbed_count: 0,
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['leads.view', 'leads.manage'];
  state.centre = centre();
  state.leads = [lead()];
  state.journey = null;
  state.duplicates = [];
  state.owners = [
    { id: 'user-2', name: 'Noura Al-Shehri' },
    { id: 'user-3', name: 'Omar Al-Qahtani' },
  ];
  state.history = [];
  state.moved = null;
  state.merged = null;
  state.assigned = null;
  state.valued = null;
  state.noted = null;
  state.crmRead = null;
  state.crmProviders = [];
});

describe('the Lead Center', () => {
  it('shows the leads and the counted taps without a ratio between them', async () => {
    await renderWithProviders(<LeadCentrePage />);

    const signals = await screen.findByRole('region', { name: 'Taps and forms begun' });

    /*
     * The one thing this page exists to prevent. 515 taps and 40 leads is a 7.8% "conversion
     * rate" that an agency would quote, and it would be indefensible: the same person taps
     * several times, or taps and then phones instead. Both numbers are stated together, and
     * nothing on the panel divides one by the other.
     */
    expect(within(signals).getByRole('alert')).toHaveTextContent('515 counted, 40 leads');
    expect(within(signals).getByRole('alert')).toHaveTextContent('no percentage is offered');
    expect(within(signals).queryByText(/%/)).not.toBeInTheDocument();

    // And the counts themselves are reachable per event, not only as one total.
    await userEvent.click(within(signals).getByRole('button', { name: 'Table' }));
    expect(
      within(within(signals).getByRole('row', { name: /whatsapp_click/ })).getByText('420'),
    ).toBeInTheDocument();
  });

  it('leaves qualified out of the funnel and says why', async () => {
    await renderWithProviders(<LeadCentrePage />);

    const pipeline = await screen.findByRole('region', { name: 'How far leads got' });

    /*
     * The stages that are genuine supersets of each other, and only those. Adding the
     * current-stage count for qualified would draw a funnel that narrows and then widens, from
     * which a reader would conclude leads were being won without qualification.
     */
    expect(within(pipeline).getByText(/Qualified is not a step here/)).toBeInTheDocument();
  });

  it('divides wins by closed leads, and says so beside the figure', async () => {
    await renderWithProviders(<LeadCentrePage />);

    const pipeline = await screen.findByRole('region', { name: 'How far leads got' });

    await userEvent.click(within(pipeline).getByRole('button', { name: 'Table' }));

    const row = within(pipeline).getByRole('row', { name: /Won/ });

    // 5 of 10 closed, not 5 of 40 — and the denominator is named in its own column.
    expect(within(row).getByText('50.0%')).toBeInTheDocument();
    expect(within(row).getByText('of the leads that closed')).toBeInTheDocument();
  });

  it('says a rate has no denominator rather than showing zero', async () => {
    state.centre = centre({
      funnel: funnel({ win_rate: null, closed: 0, won: 0, lost: 0, disqualified: 0 }),
    });

    await renderWithProviders(<LeadCentrePage />);

    /*
     * `0%` and "nothing has closed" look identical on a card and mean opposite things: the
     * first invites a comparison with last month, the second does not.
     */
    expect(await screen.findByText(/Nothing has closed yet/)).toBeInTheDocument();
  });

  it('withholds a total built from two currencies and explains the gap', async () => {
    state.centre = centre({
      funnel: funnel({
        average_won_value_minor: null,
        won_value_minor: null,
        currency: null,
        currency_count: 2,
        is_value_reportable: false,
      }),
    });

    await renderWithProviders(<LeadCentrePage />);

    // A dash with no explanation reads as a bug. The reason is a fact somebody can act on.
    expect(await screen.findByText(/2 different currencies/)).toBeInTheDocument();
    expect(screen.getByText(/more than one currency, so there is no total/)).toBeInTheDocument();
  });

  it('marks a hand-entered route as not measured', async () => {
    await renderWithProviders(<LeadCentrePage />);

    const bySource = await screen.findByRole('region', { name: 'By route' });

    await userEvent.click(within(bySource).getByRole('button', { name: 'Table' }));

    /*
     * A manual entry is a real lead and must never be reported as a campaign result. Marked in
     * the breakdown and again on every row of the pipeline.
     */
    const row = within(bySource).getByRole('row', { name: /Entered by hand/ });
    expect(within(row).getByText('Not measured')).toBeInTheDocument();
  });

  it('keeps the unattributed campaign row in the breakdown', async () => {
    await renderWithProviders(<LeadCentrePage />);

    const byCampaign = await screen.findByRole('region', { name: 'By campaign' });

    await userEvent.click(within(byCampaign).getByRole('button', { name: 'Table' }));

    /*
     * Dropping it would make every named campaign look more productive than it was, and make
     * the breakdown disagree with the funnel above it.
     */
    expect(within(byCampaign).getByRole('row', { name: /Not attributed/ })).toBeInTheDocument();
  });

  it('shows a lead nobody has contacted as waiting, not as zero hours', async () => {
    await renderWithProviders(<LeadCentrePage />);

    const row = await screen.findByRole('row', { name: /Layla Al-Harbi/ });

    // The most actionable thing on the row, and "0 h" would be the opposite of true.
    expect(within(row).getByText('Not yet')).toBeInTheDocument();
  });

  it('asks why before it lets somebody disqualify a lead', async () => {
    await renderWithProviders(<LeadCentrePage />);

    const row = await screen.findByRole('row', { name: /Layla Al-Harbi/ });

    await userEvent.click(within(row).getByRole('button', { name: 'Disqualify' }));
    await userEvent.type(within(row).getByLabelText(/Why/), 'Outside the service area');
    await userEvent.click(within(row).getByRole('button', { name: 'Disqualify' }));

    /*
     * The server refuses a disqualification without a reason, so the interface collects it
     * rather than sending a request it knows will fail — and the reason is the only record of
     * why somebody judged a prospect worthless.
     */
    expect(state.moved).toEqual({
      leadId: 'lead-1',
      stage: 'disqualified',
      reason: 'Outside the service area',
    });
  });

  it('offers no move controls to a role that may only read', async () => {
    permissions.current = ['leads.view'];

    await renderWithProviders(<LeadCentrePage />);

    const row = await screen.findByRole('row', { name: /Layla Al-Harbi/ });

    // Hiding is a courtesy; the server refuses the request regardless.
    expect(within(row).queryByRole('button', { name: 'Disqualify' })).not.toBeInTheDocument();
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<LeadCentrePage />);

    expect(
      await screen.findByText('The Lead Center is not part of your access'),
    ).toBeInTheDocument();
  });

  it('calls a single record what it is rather than drawing a journey', async () => {
    state.journey = {
      name: 'Layla Al-Harbi',
      identity_evidence: 'exact_phone',
      identity_evidence_label: 'Same phone number',
      identity_confidence: 85,
      is_reliable: true,
      touchpoint_count: 1,
      is_multi_touch: false,
      span_in_days: null,
      recorded_value_minor: 250_000,
      earliest_attributed_touchpoint: null,
      touchpoints: [
        {
          kind: 'lead',
          kind_label: 'Enquiry',
          id: 'lead-1',
          occurred_at: '2026-10-01T09:00:00+00:00',
          summary: 'form_submission — Contact form',
          state: 'new',
          attribution_evidence: 'utm_parameters',
          attribution_evidence_label: 'UTM parameters',
          attribution_confidence: 80,
          campaign: 'autumn-range',
          source: 'meta',
          value_minor: 250_000,
          currency: 'SAR',
        },
      ],
      limitations: ['The marketing before this enquiry is not available to this product.'],
    };

    await renderWithProviders(<LeadCentrePage />);

    const row = await screen.findByRole('row', { name: /Layla Al-Harbi/ });
    await userEvent.click(within(row).getByRole('button', { name: 'Layla Al-Harbi' }));

    /*
     * A one-step timeline reads as a complete picture — this customer appeared from nowhere and
     * enquired — and a reader acts on that. Saying so is the whole point.
     */
    expect(await screen.findByText(/One record, nothing else recorded/)).toBeInTheDocument();
    expect(
      screen.getByText('The marketing before this enquiry is not available to this product.'),
    ).toBeInTheDocument();
  });
});

describe('a lead the CRM owns', () => {
  it('says where its stage is moved instead of offering the controls', async () => {
    state.leads = [
      lead({
        id: 'lead-crm',
        source_kind: 'crm',
        source_kind_label: 'CRM',
        /* The CRM's own stage label travels with the lead, so a reader sees where it stands over there. */
        source_detail: 'HubSpot · Mudawalat',
        name: 'Omar',
      }),
    ];

    await renderWithProviders(<LeadCentrePage />);

    const table = await screen.findByRole('table', { name: /Leads, with their route/ });

    /*
     * The sentence goes where the controls would have been rather than the controls being hidden. A move made
     * here would be undone by the next sync without anybody being told, and a reader who cannot see why the
     * buttons are missing concludes the product is broken.
     */
    expect(
      within(table).getByText(/HubSpot · Mudawalat, which owns this stage/),
    ).toBeInTheDocument();
    expect(within(table).queryByRole('button', { name: 'Qualify' })).not.toBeInTheDocument();
    expect(within(table).queryByRole('button', { name: 'Disqualify' })).not.toBeInTheDocument();
  });

  it('still offers the controls on a lead from anywhere else', async () => {
    state.leads = [lead({ id: 'lead-form', source_kind: 'form_submission' })];

    await renderWithProviders(<LeadCentrePage />);

    const table = await screen.findByRole('table', { name: /Leads, with their route/ });

    // The refusal is about ownership, not about leads.
    expect(within(table).getByRole('button', { name: 'Mark contacted' })).toBeInTheDocument();
  });
});

describe('two leads, one person', () => {
  /*
   * Absent rather than empty. A panel saying "no duplicates found" on every lead teaches a reader to ignore it,
   * and the one time it has something to say it will be ignored too.
   */
  it('says nothing at all when no lead shares a contact point', async () => {
    await renderWithProviders(<LeadCentrePage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));

    expect(screen.queryByText('Is this the same person?')).not.toBeInTheDocument();
  });

  it('names the contact point that matched, never a similarity', async () => {
    state.duplicates = [duplicate()];

    await renderWithProviders(<LeadCentrePage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));

    const panel = await screen.findByText('Is this the same person?');

    expect(panel).toBeInTheDocument();
    expect(screen.getByText('Same phone number')).toBeInTheDocument();
  });

  /*
   * The decision the merge will not make for anybody. A lead that bought and a lead that enquired again are
   * both real, so the surviving stage is asked for — and only the two stages these leads are in are offered,
   * because a third would move a lead through a pipeline without recording a move.
   */
  it('offers only the two stages the leads are in', async () => {
    state.duplicates = [duplicate()];

    await renderWithProviders(<LeadCentrePage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Merge with this' }));

    const stages = screen.getByRole('group', { name: /Which stage/ });

    expect(within(stages).getByRole('button', { name: 'New' })).toBeInTheDocument();
    expect(within(stages).getByRole('button', { name: 'Contacted' })).toBeInTheDocument();
    expect(within(stages).queryByRole('button', { name: 'Qualified' })).not.toBeInTheDocument();
  });

  it('states what the merge will do before it is done', async () => {
    state.duplicates = [duplicate()];

    await renderWithProviders(<LeadCentrePage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Merge with this' }));

    expect(screen.getByText(/earlier arrival time is kept/)).toBeInTheDocument();
    expect(screen.getByText(/there is no undo/)).toBeInTheDocument();
  });

  it('will not merge until a surviving stage is chosen', async () => {
    state.duplicates = [duplicate()];

    await renderWithProviders(<LeadCentrePage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Merge with this' }));

    expect(screen.getByRole('button', { name: 'Merge these leads' })).toBeDisabled();

    await userEvent.click(
      within(screen.getByRole('group', { name: /Which stage/ })).getByRole('button', {
        name: 'Contacted',
      }),
    );
    await userEvent.click(screen.getByRole('button', { name: 'Merge these leads' }));

    expect(state.merged).toEqual({
      leadId: 'lead-1',
      otherLeadId: 'lead-2',
      stage: 'contacted',
    });
  });

  it('does not offer the merge to somebody who may not manage leads', async () => {
    permissions.current = ['leads.view'];
    state.duplicates = [duplicate()];

    await renderWithProviders(<LeadCentrePage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));

    /* The panel itself never loads for them, because the query that feeds it needs the same permission. */
    expect(screen.queryByText('Is this the same person?')).not.toBeInTheDocument();
  });
});

describe('working a lead', () => {
  async function openLead() {
    await renderWithProviders(<LeadCentrePage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));
  }

  /*
   * The owner list is the server's, not the member list. A lead carries a prospect's name and phone number, so
   * only colleagues who can open this client's leads are offered — and every name shown is one the server will
   * accept.
   */
  it('offers the people the server says can open this lead', async () => {
    await openLead();

    const owner = screen.getByRole('combobox', { name: 'Owner' });

    expect(within(owner).getByRole('option', { name: 'Noura Al-Shehri' })).toBeInTheDocument();

    await userEvent.selectOptions(owner, 'user-2');

    expect(state.assigned).toEqual({ leadId: 'lead-1', ownerId: 'user-2' });
  });

  /* Unassigning is a choice, not an absence: a pipeline that can only add owners accumulates them. */
  it('offers taking the lead back out of somebody’s name', async () => {
    await openLead();

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Owner' }), '');

    expect(state.assigned).toEqual({ leadId: 'lead-1', ownerId: null });
  });

  it('sends a value in minor units, from an amount typed in major', async () => {
    state.leads = [lead({ value_minor: null, currency: null })];

    await openLead();

    await userEvent.type(screen.getByRole('textbox', { name: 'Amount' }), '1250.50');
    await userEvent.click(screen.getByRole('button', { name: 'Save the value' }));

    expect(state.valued).toEqual({ leadId: 'lead-1', valueMinor: 125_050, currency: 'SAR' });
  });

  /*
   * Clearing is its own control, because null and zero are different claims: nobody has said, versus somebody
   * saying it is worth nothing.
   */
  it('clears a value without claiming it is zero', async () => {
    await openLead();

    await userEvent.click(screen.getByRole('button', { name: 'Clear it' }));

    expect(state.valued).toEqual({ leadId: 'lead-1', valueMinor: null, currency: null });
  });

  it('says nobody has said, rather than showing a zero', async () => {
    state.leads = [lead({ value_minor: null, currency: null })];

    await openLead();

    expect(screen.getByText(/Nobody has said yet/)).toBeInTheDocument();
  });

  /*
   * The CRM holds the amount. A figure typed here would be undone by the next sync — after both numbers had been
   * quoted to the same client — so the sentence goes where the control would have been.
   */
  it('refuses to offer a value control on a CRM-sourced lead, and says why', async () => {
    state.leads = [lead({ source_kind: 'crm', source_detail: 'HubSpot · Proposal sent' })];

    await openLead();

    expect(screen.getByText(/holds this deal.s amount/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save the value' })).not.toBeInTheDocument();
  });

  it('trims a note before sending it, and refuses an empty one', async () => {
    await openLead();

    const save = screen.getByRole('button', { name: 'Add to the history' });

    expect(save).toBeDisabled();

    await userEvent.type(screen.getByRole('textbox', { name: 'What happened' }), '  Rang twice.  ');
    await userEvent.click(save);

    expect(state.noted).toEqual({ leadId: 'lead-1', note: 'Rang twice.' });
  });

  it('shows none of it to somebody who may not manage leads', async () => {
    permissions.current = ['leads.view'];

    await openLead();

    expect(screen.queryByText('Working this lead')).not.toBeInTheDocument();
  });
});

describe('what has happened to a lead', () => {
  const entry = (overrides: Partial<LeadHistoryEntry> = {}): LeadHistoryEntry => ({
    id: 'event-1',
    type: 'note_added',
    type_label: 'Note',
    actor_name: 'Noura Al-Shehri',
    from_stage: null,
    from_stage_label: null,
    to_stage: null,
    to_stage_label: null,
    note: 'Rang twice, no answer.',
    occurred_at: '2026-10-05T09:00:00+00:00',
    ...overrides,
  });

  /*
   * The read half of the notes. Without it a note is a write nobody can see, which is a worse surface than
   * having no notes at all.
   */
  it('shows a note that was written', async () => {
    state.history = [entry()];

    await renderWithProviders(<LeadCentrePage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));

    expect(await screen.findByText('Rang twice, no answer.')).toBeInTheDocument();
    expect(screen.getByText('by Noura Al-Shehri')).toBeInTheDocument();
  });

  /*
   * "Who moved this" has an honest answer including when it is nobody. A CRM sync has no actor, and filling the
   * column in with whoever happened to be signed in would make the trail say something untrue.
   */
  it('says the system did it where there was no person', async () => {
    state.history = [
      entry({
        type: 'stage_changed',
        type_label: 'Moved',
        actor_name: null,
        note: null,
        from_stage: 'new',
        from_stage_label: 'New',
        to_stage: 'won',
        to_stage_label: 'Won',
      }),
    ];

    await renderWithProviders(<LeadCentrePage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));

    expect(await screen.findByText('recorded by the system')).toBeInTheDocument();
    expect(screen.getByText('New → Won')).toBeInTheDocument();
  });

  it('says nothing is recorded rather than showing an empty list', async () => {
    await renderWithProviders(<LeadCentrePage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));

    expect(await screen.findByText('Nothing recorded yet')).toBeInTheDocument();
  });

  /* Reading a pipeline is how it gets reviewed, and the readers are not only the writers. */
  it('is shown to somebody who may read leads but not manage them', async () => {
    permissions.current = ['leads.view'];
    state.history = [entry()];

    await renderWithProviders(<LeadCentrePage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Layla Al-Harbi' }));

    expect(await screen.findByText('Rang twice, no answer.')).toBeInTheDocument();
    expect(screen.queryByText('Working this lead')).not.toBeInTheDocument();
  });
});

describe('reading a CRM', () => {
  const hubspot = {
    provider: 'hubspot',
    label: 'HubSpot',
    category: 'crm',
    connection_id: 'connection-1',
  };

  /*
   * Absent when there is no CRM connected. A panel on every workspace that has never connected one would be an
   * advertisement, and the place to connect a provider is the integrations page.
   */
  it('says nothing when no CRM is connected', async () => {
    await renderWithProviders(<LeadCentrePage />);

    await screen.findByRole('heading', { level: 1 });

    expect(screen.queryByText(/Deals in/)).not.toBeInTheDocument();
  });

  /*
   * The binding, asked for rather than inferred. A CRM portal belongs to one company and a workspace has several
   * clients, so nothing here guesses which client's deals those are.
   */
  it('asks for a client before offering to read anything', async () => {
    state.crmProviders = [hubspot];

    await renderWithProviders(<LeadCentrePage />);

    expect(await screen.findByText('Deals in HubSpot')).toBeInTheDocument();
    expect(screen.getByText(/Choose a client above first/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Read the deals' })).not.toBeInTheDocument();
  });

  it('reads for the chosen client, and reports what it left alone', async () => {
    state.crmProviders = [hubspot];

    await renderWithProviders(<LeadCentrePage />);

    await chooseClient('Noon');
    await userEvent.click(screen.getByRole('button', { name: 'Read the deals' }));

    expect(state.crmRead).toBe('client-1');

    /*
     * `left_alone` beside `recorded`, because a bridge reporting only the deals it understood would look like one
     * that read almost nothing — see `CrmStageMeaning`.
     */
    expect(await screen.findByText(/1 left at the stage the CRM owns/)).toBeInTheDocument();
  });

  /*
   * A count is as quotable to a client as a chart is, so a sandbox read says so — from the server's own answer
   * rather than guessed from the connection.
   */
  it('never lets a sandbox read pass as the client’s own pipeline', async () => {
    state.crmProviders = [hubspot];

    await renderWithProviders(<LeadCentrePage />);

    await chooseClient('Noon');
    await userEvent.click(screen.getByRole('button', { name: 'Read the deals' }));

    expect(await screen.findByText(/came from sandbox fixtures/)).toBeInTheDocument();
  });

  it('does not offer the read to somebody who may not manage leads', async () => {
    permissions.current = ['leads.view'];
    state.crmProviders = [hubspot];

    await renderWithProviders(<LeadCentrePage />);

    await screen.findByRole('heading', { level: 1 });

    expect(screen.queryByText(/Deals in/)).not.toBeInTheDocument();
  });
});
