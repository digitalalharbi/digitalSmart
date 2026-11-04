import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { ReconciliationPanel } from './ReconciliationPanel';
import type { RevenueReconciliation } from './types';

const state = vi.hoisted((): { data: unknown; fails: boolean } => ({ data: null, fails: false }));

vi.mock('./api', () => ({
  fetchReconciliation: () =>
    state.fails ? Promise.reject(new Error('down')) : Promise.resolve(state.data),
}));

function reconciliation(overrides: Partial<RevenueReconciliation> = {}): RevenueReconciliation {
  return {
    period: { from: '2026-10-01', until: '2026-10-28' },
    authority: 'store',
    stores: [],
    store_paid_orders: 20,
    store_net_revenue_minor: 2_000_000,
    store_currency: 'SAR',
    claims: [
      {
        source: 'platform_reported',
        metric_source: 'sandbox',
        purchases: 26,
        revenue_minor: 2_600_000,
        currency: 'SAR',
        purchases_delta: 6,
        purchases_ratio: 1.3,
        revenue_delta_minor: 600_000,
        unavailable_reason: null,
      },
      {
        source: 'google_analytics',
        metric_source: 'sandbox',
        purchases: 30,
        revenue_minor: null,
        currency: null,
        purchases_delta: 10,
        purchases_ratio: 1.5,
        revenue_delta_minor: null,
        unavailable_reason: 'Purchase events only.',
      },
      {
        source: 'tracking_evidence',
        metric_source: 'synced',
        purchases: 5,
        revenue_minor: 500_000,
        currency: 'SAR',
        purchases_delta: -15,
        purchases_ratio: 0.25,
        revenue_delta_minor: -1_500_000,
        unavailable_reason: null,
      },
    ],
    ga4: { days_present: 28, days_in_period: 28 },
    note: '',
    ...overrides,
  };
}

function row(name: RegExp) {
  return within(screen.getByRole('row', { name }));
}

async function renderPanel() {
  await renderWithProviders(
    <ReconciliationPanel clientId="client-1" from="2026-10-01" until="2026-10-28" locale="en" />,
  );
  await screen.findByText('Who says what was sold');
}

describe('reconciliation panel', () => {
  beforeEach(() => {
    state.data = reconciliation();
    state.fails = false;
  });

  it('puts the store first as the authority and each claim beside it with its difference', async () => {
    await renderPanel();

    // The first row after the header is the store.
    expect(screen.getAllByRole('rowheader')[0]).toHaveTextContent('Store (paid orders)');
    expect(row(/Store \(paid orders\)/).getAllByText('Authority')).toHaveLength(2);
    expect(row(/Ad platforms report/).getByText('+6')).toBeInTheDocument();
    expect(row(/Google Analytics purchase events/).getByText('+10')).toBeInTheDocument();
    expect(row(/Store orders with tracking evidence/).getByText('-15')).toBeInTheDocument();
  });

  it('has no total row: the sources are never added together', async () => {
    await renderPanel();

    // A header, the store, and three claims — nothing that sums them.
    expect(screen.getAllByRole('row')).toHaveLength(5);
    expect(screen.queryByText(/total/i)).not.toBeInTheDocument();
  });

  it('says not available, never nought, where a figure or a difference is missing', async () => {
    await renderPanel();

    // GA4 revenue is not read: no amount and no money difference.
    expect(row(/Google Analytics purchase events/).getAllByText('Not available')).toHaveLength(2);
  });

  it('labels a sandbox claim as sandbox', async () => {
    await renderPanel();

    expect(row(/Ad platforms report/).getByText('Sandbox')).toBeInTheDocument();
  });

  it('says there is nothing to measure against when no store was read', async () => {
    state.data = reconciliation({
      store_paid_orders: null,
      store_net_revenue_minor: null,
      store_currency: null,
    });

    await renderPanel();

    expect(screen.getByText(/nothing to measure the other sources against/)).toBeInTheDocument();
  });

  it('says so when the comparison cannot be loaded', async () => {
    state.fails = true;

    await renderWithProviders(
      <ReconciliationPanel clientId="client-1" from="2026-10-01" until="2026-10-28" locale="en" />,
    );

    expect(
      await screen.findByText('The comparison between sources could not be loaded.'),
    ).toBeInTheDocument();
  });
});
