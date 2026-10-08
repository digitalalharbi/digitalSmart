import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { LeadCentrePage } from './LeadCentrePage';
import type { Journey, Lead, LeadCentre, LeadFunnel } from './types';

/*
 * ECharts needs a real canvas and a measured container, neither of which jsdom has. The
 * chart's own behaviour is the chart component's business; here we only need to know the page
 * asked for one — and the table twin, which this page's figures are also reachable through,
 * is real DOM and is asserted directly.
 */
vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Noon Retail' }] },
  }),
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
  moved: null as { leadId: string; stage: string; reason?: string } | null,
}));

vi.mock('./api', () => ({
  fetchLeadCentre: () => Promise.resolve(state.centre),
  fetchLeads: () => Promise.resolve(state.leads),
  fetchJourney: () => Promise.resolve(state.journey),
  changeStage: (change: { leadId: string; stage: string; reason?: string }) => {
    state.moved = change;

    return Promise.resolve(state.leads[0]);
  },
}));

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
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['leads.view', 'leads.manage'];
  state.centre = centre();
  state.leads = [lead()];
  state.journey = null;
  state.moved = null;
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
