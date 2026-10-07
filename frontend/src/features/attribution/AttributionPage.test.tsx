import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { AttributionPage } from './AttributionPage';
import type {
  AttributedRow,
  AttributionAnswer,
  AttributionComparison,
  AttributionCoverage,
  AttributionModelInfo,
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

const permissions = vi.hoisted(() => ({ current: ['analytics.view'] as string[] }));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: { analyticsView: 'analytics.view' },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  models: [] as AttributionModelInfo[],
  answer: null as AttributionAnswer | null,
  comparison: null as AttributionComparison | null,
  lastQuery: null as Record<string, unknown> | null,
  failWith: null as Error | null,
}));

vi.mock('./api', () => ({
  fetchAttributionModels: () => Promise.resolve(state.models),
  fetchAttribution: (query: Record<string, unknown>) => {
    state.lastQuery = query;

    if (state.failWith !== null) {
      return Promise.reject(state.failWith);
    }

    return Promise.resolve(state.answer);
  },
  fetchComparison: () => Promise.resolve(state.comparison),
}));

function model(overrides: Partial<AttributionModelInfo> = {}): AttributionModelInfo {
  return {
    model: 'last_recorded',
    label: 'As recorded on the order',
    description: 'Credits the campaign written onto the order at checkout.',
    is_modelled: false,
    is_available: true,
    needs_person: false,
    requires: 'conversion_record',
    requires_label: 'What the conversion carried',
    missing: null,
    would_unblock: null,
    ...overrides,
  };
}

function coverage(overrides: Partial<AttributionCoverage> = {}): AttributionCoverage {
  return {
    conversions: 100,
    attributed_conversions: 80,
    campaign_named_conversions: 60,
    revenue_minor: 1_000_000,
    attributed_revenue_minor: 800_000,
    unattributed_revenue_minor: 200_000,
    currency: 'SAR',
    conversion_coverage: 80,
    revenue_coverage: 80,
    campaign_named_share: 75,
    weakest_evidence: 'coupon_code',
    weakest_evidence_label: 'Coupon code',
    confidence: 72,
    is_reportable: true,
    reportable_threshold: 70,
    ...overrides,
  };
}

function row(overrides: Partial<AttributedRow> = {}): AttributedRow {
  return {
    label: 'autumn-range',
    link: 'exact_name',
    link_label: 'Matched by campaign name',
    link_explanation: null,
    joins_spend: true,
    provider: 'meta',
    conversions: 10,
    revenue_minor: 500_000,
    currency: 'SAR',
    average_revenue_minor: 50_000,
    evidence: 'utm_parameters',
    evidence_label: 'Campaign parameters',
    confidence: 80,
    spend_minor: 100_000,
    spend_source: 'synced',
    roas: 5,
    cost_per_conversion_minor: 10_000,
    ...overrides,
  };
}

function answer(overrides: Partial<AttributionAnswer> = {}): AttributionAnswer {
  return {
    model: 'last_recorded',
    model_label: 'As recorded on the order',
    is_modelled: false,
    revenue_source: 'salla',
    revenue_source_label: 'Salla',
    is_invoiced: true,
    lookback_days: 30,
    from: '2026-10-01',
    until: '2026-10-30',
    coverage: coverage(),
    rows: [row()],
    unmatched_spend_minor: 220_000,
    spend_currency: 'SAR',
    caveats: [],
    ...overrides,
  };
}

function comparison(overrides: Partial<AttributionComparison> = {}): AttributionComparison {
  return {
    models: ['last_recorded', 'first_recorded'],
    revenue_source: 'salla',
    from: '2026-10-01',
    until: '2026-10-30',
    material_disagreements: 1,
    campaigns: [
      {
        label: 'autumn-range',
        revenue_minor_by_model: { last_recorded: 500_000, first_recorded: 100_000 },
        currency: 'SAR',
        highest_minor: 500_000,
        lowest_minor: 100_000,
        spread_percent: 80,
        is_material: true,
      },
    ],
    views: {},
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['analytics.view'];
  state.models = [
    model(),
    model({
      model: 'first_recorded',
      label: 'First recorded conversion',
      is_modelled: true,
      needs_person: true,
      requires: 'person_history',
    }),
    model({
      model: 'time_decay',
      label: 'Time decay',
      is_modelled: true,
      is_available: false,
      requires: 'touch_path',
      missing: 'The marketing touches before a conversion are not available to this product.',
      would_unblock: 'Either a Google Analytics to BigQuery export, or first-party tracking.',
    }),
  ];
  state.answer = answer();
  state.comparison = comparison();
  state.failWith = null;
  state.lastQuery = null;
});

describe('attribution', () => {
  it('marks a model as a model and a record as a record', async () => {
    await renderWithProviders(<AttributionPage />);

    const recorded = await screen.findByRole('button', { name: /As recorded on the order/ });
    const modelled = screen.getByRole('button', { name: /First recorded conversion/ });

    /*
     * The distinction the whole phase rests on. One of these figures can be quoted as what happened;
     * the other is a claim about which campaign deserves credit.
     */
    expect(within(recorded).getByText('Recorded')).toBeInTheDocument();
    expect(within(modelled).getByText('Modelled')).toBeInTheDocument();
  });

  it('offers a model it cannot compute, disabled, with what would unblock it', async () => {
    await renderWithProviders(<AttributionPage />);

    const unavailable = await screen.findByRole('button', { name: /Time decay/ });

    /*
     * Shown rather than hidden. Hiding it is the easier interface and the worse one: the question gets
     * asked of an engineer every quarter, and the answer is a decision for whoever pays.
     */
    expect(unavailable).toBeDisabled();
    expect(within(unavailable).getByText('Cannot be computed')).toBeInTheDocument();
    expect(within(unavailable).getByText(/not available to this product/)).toBeInTheDocument();
    expect(within(unavailable).getByText(/BigQuery/)).toBeInTheDocument();
  });

  it('leads with how much of the period the figures account for', async () => {
    await renderWithProviders(<AttributionPage />);

    expect(await screen.findByRole('meter')).toHaveAttribute('aria-valuenow', '80');

    // The figure a reader most needs and least expects, published rather than left to subtraction.
    expect(screen.getByText('No campaign can be credited')).toBeInTheDocument();
    expect(screen.getByText(/SAR\s*2,000\.00/)).toBeInTheDocument();
  });

  it('warns rather than leading with the rows when coverage is thin', async () => {
    state.answer = answer({
      coverage: coverage({
        revenue_coverage: 30,
        attributed_revenue_minor: 300_000,
        unattributed_revenue_minor: 700_000,
        is_reportable: false,
      }),
    });

    await renderWithProviders(<AttributionPage />);

    /*
     * A 30%-coverage report put in front of a client without this sentence will be quoted as the whole
     * month. The sentence is the mitigation, so it is an alert rather than a footnote.
     */
    expect(await screen.findByText(/Treat the rows below as a sample/)).toBeInTheDocument();
    expect(screen.getByText('Below 70%')).toBeInTheDocument();
  });

  it('shows the reason a row carries no spend', async () => {
    state.answer = answer({
      rows: [
        row({
          label: 'autumn-range-v2',
          link: 'no_matching_campaign',
          link_label: 'No campaign of that name',
          link_explanation: 'No campaign of this name exists in the connected ad accounts.',
          joins_spend: false,
          spend_minor: null,
          roas: null,
        }),
      ],
    });

    await renderWithProviders(<AttributionPage />);

    const frame = await screen.findByRole('region', { name: 'Attributed revenue by campaign' });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    const tableRow = within(frame).getByRole('row', { name: /autumn-range-v2/ });

    /*
     * A blank is a report somebody quietly distrusts. "No campaign of this name exists in the connected
     * accounts" is a tagging problem somebody can fix today.
     */
    expect(within(tableRow).getByText(/No campaign of this name exists/)).toBeInTheDocument();
    expect(within(frame).queryByText('5.00')).not.toBeInTheDocument();
  });

  it('reports spend the orders never mentioned', async () => {
    await renderWithProviders(<AttributionPage />);

    // Half the question an agency is asked, and the half a report of winning campaigns leaves out.
    expect(
      await screen.findByText(/of ad spend was on campaigns no order mentioned/),
    ).toBeInTheDocument();
  });

  it('leads the comparison with how much the choice of model is worth', async () => {
    await renderWithProviders(<AttributionPage />);

    /*
     * Said before the chart and outside the frame, so it is still on screen in the table view. A reader
     * who takes one number off the chart has already missed the point, and the point is that neither
     * number is what the campaign earned.
     */
    expect(
      await screen.findByText(
        /On 1 campaigns the choice of model moves the figure by more than a fifth/,
      ),
    ).toBeInTheDocument();
  });

  it('shows a truncated model as a gap rather than a zero', async () => {
    state.comparison = comparison({
      campaigns: [
        {
          label: 'tail-row',
          revenue_minor_by_model: { last_recorded: 80_000, first_recorded: null },
          currency: 'SAR',
          highest_minor: 80_000,
          lowest_minor: 80_000,
          spread_percent: 0,
          is_material: false,
        },
      ],
      material_disagreements: 0,
    });

    await renderWithProviders(<AttributionPage />);

    const frame = await screen.findByRole('region', { name: 'What the choice of model is worth' });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    /*
     * Null is not zero. Recording a campaign missing from a truncated breakdown as zero would
     * manufacture a total disagreement out of a row limit.
     */
    const tableRow = within(frame).getByRole('row', { name: /tail-row/ });
    expect(within(tableRow).getByText('—')).toBeInTheDocument();
    expect(screen.getByText(/agree closely/)).toBeInTheDocument();
  });

  it("shows the server's own refusal for a model it cannot compute", async () => {
    const { ApiError } = await import('@/shared/api/client');

    state.failWith = new ApiError(
      'Time decay attribution cannot be computed. The marketing touches before a conversion are not available to this product.',
      422,
    );

    await renderWithProviders(<AttributionPage />);

    // The server's words, which name what is missing. A generic failure would not.
    expect(
      await screen.findByText(/Time decay attribution cannot be computed/),
    ).toBeInTheDocument();
  });

  it('offers the lookback window only for a model that reads a history', async () => {
    await renderWithProviders(<AttributionPage />);

    const history = await screen.findByRole('button', { name: /First recorded conversion/ });

    // Not shown for the recorded model, where it would change nothing.
    expect(screen.queryByText('Look back')).not.toBeInTheDocument();

    await userEvent.click(history);

    /*
     * A control that silently does nothing teaches a reader to distrust the others, so it appears only
     * where it has an effect — and it has one, because a different window credits different campaigns.
     */
    expect(await screen.findByText('Look back')).toBeInTheDocument();
  });

  it('sends the model and the authority on every request, with no defaults of its own', async () => {
    await renderWithProviders(<AttributionPage />);

    await screen.findByRole('meter');

    expect(state.lastQuery).toMatchObject({ model: 'last_recorded', revenue_source: 'salla' });

    await userEvent.click(screen.getByRole('button', { name: 'Zid' }));

    await vi.waitFor(() => {
      expect(state.lastQuery).toMatchObject({ revenue_source: 'zid' });
    });
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<AttributionPage />);

    expect(await screen.findByText('Attribution is not part of your access')).toBeInTheDocument();
  });
});
