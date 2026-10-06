import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { StoreAnalyticsPage } from './StoreAnalyticsPage';
import type { RevenueBreakdownRow, StoreAnalytics, StoreRevenueSummary } from './types';

/*
 * ECharts needs a real canvas and a measured container, neither of which jsdom has. The chart's own
 * behaviour — including the accessible description it must carry — is the chart component's business;
 * here we only need to know the page asked for one.
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

const state = vi.hoisted(() => ({
  analytics: null as StoreAnalytics | null,
  lastSource: null as string | null,
}));

vi.mock('./api', () => ({
  fetchStoreAnalytics: (query: { revenue_source: string }) => {
    state.lastSource = query.revenue_source;

    return Promise.resolve(state.analytics);
  },
}));

function summary(overrides: Partial<StoreRevenueSummary> = {}): StoreRevenueSummary {
  return {
    revenue_source: 'salla',
    revenue_source_label: 'Salla',
    is_invoiced: true,
    is_modelled: false,
    source: 'synced',
    source_label: 'Synced',
    currency: 'SAR',
    orders: 4,
    revenue_minor: 120_000,
    refunded_minor: 20_000,
    net_revenue_minor: 100_000,
    average_order_value_minor: 25_000,
    units: 9,
    customers: 3,
    pending_orders: 0,
    pending_revenue_minor: 0,
    attributed_orders: 3,
    attributed_revenue_minor: 80_000,
    attribution_coverage: 80,
    ...overrides,
  };
}

function row(overrides: Partial<RevenueBreakdownRow> = {}): RevenueBreakdownRow {
  return {
    label: 'meta / cpc',
    orders: 2,
    revenue_minor: 80_000,
    refunded_minor: 0,
    net_revenue_minor: 80_000,
    units: 0,
    evidence: 'utm_parameters',
    evidence_label: 'Campaign parameters',
    confidence: 80,
    is_unattributed: false,
    external_id: null,
    ...overrides,
  };
}

function analytics(overrides: Partial<StoreAnalytics> = {}): StoreAnalytics {
  return {
    from: '2026-09-07',
    until: '2026-10-06',
    summary: summary(),
    by_channel: [row()],
    by_campaign: [row({ label: 'eid-launch' })],
    by_product: [
      row({ label: 'Abaya', units: 9, evidence: null, evidence_label: null, confidence: null }),
    ],
    daily: [
      { date: '2026-10-01', orders: 2, net_revenue_minor: 60_000, refunded_minor: 0 },
      { date: '2026-10-02', orders: 2, net_revenue_minor: 40_000, refunded_minor: 20_000 },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  state.analytics = analytics();
  state.lastSource = null;
});

describe('store revenue', function () {
  it('asks for one named authority and says which one it got', async () => {
    await renderWithProviders(<StoreAnalyticsPage />);

    await screen.findByText(/Salla invoiced this money/);

    /*
     * A Salla total counts invoices; a GA4 total counts what a browser reported before the payment
     * failed. The request always names its authority, and the page repeats it in words rather than
     * leaving it to a badge.
     */
    expect(state.lastSource).toBe('salla');
  });

  it('asks a different question when the authority changes', async () => {
    await renderWithProviders(<StoreAnalyticsPage />);

    await screen.findByText(/Salla invoiced this money/);

    state.analytics = analytics({
      summary: summary({
        revenue_source: 'google_analytics',
        revenue_source_label: 'Google Analytics',
        is_invoiced: false,
      }),
    });

    await userEvent.click(screen.getByRole('button', { name: 'Google Analytics' }));

    /*
     * Changing the source does not refine the answer, it asks a different question — so the query key
     * includes it and a fresh request goes out rather than a cached Salla total being relabelled.
     */
    expect(await screen.findByText(/measurement of sales, not a record/)).toBeInTheDocument();
    expect(state.lastSource).toBe('google_analytics');
  });

  it('warns plainly when the figure is modelled rather than recorded', async () => {
    state.analytics = analytics({
      summary: summary({
        revenue_source: 'attributed',
        revenue_source_label: 'Attributed',
        is_invoiced: false,
        is_modelled: true,
      }),
    });

    await renderWithProviders(<StoreAnalyticsPage />);

    // Attributed revenue is a model's output and must never be presented to a client as actual.
    expect(
      await screen.findByText(/must not be presented to a client as actual/),
    ).toBeInTheDocument();
  });

  it('shows net revenue with the gross and the refunds beside it', async () => {
    await renderWithProviders(<StoreAnalyticsPage />);

    /*
     * A refund should be visible rather than inferred from a smaller number, so the gross and the
     * refunded amount are stated beside the net figure.
     */
    expect(
      await screen.findByText(/1,200\.00.*sold.*200\.00.*refunded.*9 units/),
    ).toBeInTheDocument();
  });

  it('shows a dash rather than zero when no order counted', async () => {
    state.analytics = analytics({
      summary: summary({
        orders: 0,
        net_revenue_minor: 0,
        revenue_minor: 0,
        refunded_minor: 0,
        average_order_value_minor: null,
        attribution_coverage: null,
        pending_orders: 2,
        pending_revenue_minor: 50_000,
      }),
    });

    await renderWithProviders(<StoreAnalyticsPage />);

    /*
     * A denominator of zero has no average. The reason travels with the absence, so a reader is told why
     * rather than left to assume the figure is genuinely nil.
     */
    expect(await screen.findByText(/no average to show/)).toBeInTheDocument();
  });

  it('reports pending orders beside the total, never inside it', async () => {
    state.analytics = analytics({
      summary: summary({ pending_orders: 3, pending_revenue_minor: 75_000 }),
    });

    await renderWithProviders(<StoreAnalyticsPage />);

    /*
     * Cash on delivery is normal in this market, so this is most of a busy week rather than an edge
     * case. Counting it would overstate every figure above until the courier either collects or comes
     * back; hiding it would conceal real demand.
     */
    expect(await screen.findByText(/3 orders are placed and not yet paid for/)).toBeInTheDocument();
  });

  it('puts attribution coverage beside the breakdowns', async () => {
    await renderWithProviders(<StoreAnalyticsPage />);

    /*
     * A channel report at 30% coverage is a different document from one at 95%, and a reader needs to
     * know which they are holding before they read a single row.
     */
    expect(await screen.findByText('80% traceable')).toBeInTheDocument();
  });

  it('shows the weakest evidence a row rests on', async () => {
    await renderWithProviders(<StoreAnalyticsPage />);

    const channels = await screen.findByRole('table', { name: 'By channel' });

    // A row crediting a campaign on a coupon is a different claim from one on a click identifier.
    expect(within(channels).getByText('Campaign parameters · 80%')).toBeInTheDocument();
  });

  it('marks the unattributed row rather than hiding it', async () => {
    state.analytics = analytics({
      by_channel: [
        row(),
        row({
          label: 'Unattributed',
          orders: 5,
          net_revenue_minor: 200_000,
          revenue_minor: 200_000,
          evidence: null,
          evidence_label: null,
          confidence: null,
          is_unattributed: true,
        }),
      ],
    });

    await renderWithProviders(<StoreAnalyticsPage />);

    const channels = await screen.findByRole('table', { name: 'By channel' });

    /*
     * It is usually the largest row. A reader who cannot see it reads every other row as a larger share
     * of the business than it is.
     */
    expect(within(channels).getByText('Unattributed')).toBeInTheDocument();
  });

  it('says so when there is nothing to show for the chosen authority', async () => {
    state.analytics = analytics({
      summary: summary({
        orders: 0,
        pending_orders: 0,
        net_revenue_minor: 0,
        revenue_minor: 0,
        refunded_minor: 0,
        average_order_value_minor: null,
        attribution_coverage: null,
      }),
      by_channel: [],
      by_campaign: [],
      by_product: [],
      daily: [],
    });

    await renderWithProviders(<StoreAnalyticsPage />);

    // Named for the authority asked about, because "no revenue" from one source is not from another.
    expect(await screen.findByText('No Salla revenue in this period')).toBeInTheDocument();
  });

  it('does not draw a trend from a single day', async () => {
    state.analytics = analytics({
      daily: [{ date: '2026-10-01', orders: 2, net_revenue_minor: 60_000, refunded_minor: 0 }],
    });

    await renderWithProviders(<StoreAnalyticsPage />);

    // A one-point line is decoration, and this product's charts are supposed to answer a question.
    expect(await screen.findByText(/A single day is not a trend/)).toBeInTheDocument();
  });
});
