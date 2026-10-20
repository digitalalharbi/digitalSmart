import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { StoreDetailView } from './StoreDetailView';
import type { StoreDetail } from './types';

const base: StoreDetail = {
  currency: 'SAR',
  daily: [
    { date: '2026-10-01', orders: 3, net_revenue: 30_000 },
    { date: '2026-10-02', orders: 5, net_revenue: 90_000 },
    { date: '2026-10-05', orders: 2, net_revenue: 10_000 },
  ],
  stores: [],
  products: [],
  channels: [],
  attribution_coverage: null,
  customers: null,
  refunds: { amount: 0, orders: 0, rate: null, previous_rate: null },
  previous: null,
};

function show(detail: StoreDetail, orders = 10) {
  return renderWithProviders(<StoreDetailView detail={detail} orders={orders} authority="Salla" />);
}

describe('StoreDetailView', () => {
  it('names the authority on the revenue trend and says a quiet day is a gap', async () => {
    await show(base);

    expect(await screen.findByText('Net revenue by day — Salla')).toBeInTheDocument();
    expect(screen.getByText('Orders by day')).toBeInTheDocument();
    expect(screen.getByText(/gap in the line, not a zero/)).toBeInTheDocument();
  });

  it('draws nothing at all for a section with nothing to show', async () => {
    const { container } = await show({ ...base, daily: [] });

    expect(container.textContent).toBe('');
  });

  it('hides the store comparison for one store and shows it for two', async () => {
    const one = { name: 'A', orders: 6, net_revenue: 60_000, refunded: 0, aov: 10_000, share: 100 };
    const two = { name: 'B', orders: 4, net_revenue: 20_000, refunded: 500, aov: 5_000, share: 25 };

    const first = await show({ ...base, stores: [] });
    expect(screen.queryByText('Stores')).not.toBeInTheDocument();
    first.unmount();

    await show({ ...base, stores: [{ ...one, share: 75 }, two] });
    expect(await screen.findByText('Stores')).toBeInTheDocument();
    expect(screen.getByText('B')).toBeInTheDocument();
  });

  it('shows the refund rate as not available, never as zero, and warns only with an earlier rate', async () => {
    await show({ ...base, refunds: { amount: 5_000, orders: 2, rate: null, previous_rate: null } });

    expect(await screen.findByText('Refunds')).toBeInTheDocument();
    expect(screen.queryByText(/points higher/)).not.toBeInTheDocument();
    expect(screen.queryByText('0%')).not.toBeInTheDocument();
  });

  it('warns when the refund rate rose against the earlier period', async () => {
    await show({ ...base, refunds: { amount: 5_000, orders: 2, rate: 8, previous_rate: 4 } });

    expect(await screen.findByText(/4 points higher/)).toBeInTheDocument();
  });

  it("leaves a customer's identity out: counts only", async () => {
    await show({
      ...base,
      customers: { new: 3, returning: 2, unknown: 0, new_share: 0.6, unavailable_reason: null },
    });

    expect(await screen.findByText('New and returning customers')).toBeInTheDocument();
    expect(screen.getByText('New')).toBeInTheDocument();
  });

  it('shows no conversion rate or funnel, because a store report holds no sessions', async () => {
    await show(base);

    expect(screen.queryByText(/conversion rate/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/funnel/i)).not.toBeInTheDocument();
  });
});
