import { screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { StoresRollupPage } from './StoresRollupPage';
import type { StoreRollup } from './useStoreRollup';

const state = vi.hoisted(() => ({ rollup: undefined as StoreRollup | undefined }));

vi.mock('./useStoreRollup', () => ({
  useStoreRollup: () => ({
    data: state.rollup,
    isPending: state.rollup === undefined,
    isError: false,
  }),
}));
vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));

function store(
  overrides: Partial<StoreRollup['stores'][number]> = {},
): StoreRollup['stores'][number] {
  return {
    store_id: 's1',
    store_name: 'Alpha',
    provider: 'salla',
    client_id: 'c1',
    client_name: 'Noon Retail',
    currency: 'SAR',
    is_active: true,
    orders: 2,
    revenue_minor: 30_000,
    refunded_minor: 1_000,
    net_revenue_minor: 29_000,
    average_order_minor: 15_000,
    attributed_revenue_minor: 9_000,
    last_synced_at: null,
    ...overrides,
  };
}

describe('StoresRollupPage', () => {
  beforeEach(() => {
    state.rollup = undefined;
  });

  it('shows each store in its own currency and totals nothing', async () => {
    state.rollup = {
      revenue_source: 'salla',
      from: '2026-09-16',
      until: '2026-10-15',
      stores: [
        store(),
        store({
          store_id: 's2',
          store_name: 'Beta',
          currency: 'USD',
          client_name: 'Other',
          revenue_minor: 5_000,
        }),
      ],
    };
    await renderWithProviders(<StoresRollupPage />);

    expect(await screen.findByText('Alpha')).toBeInTheDocument();
    expect(screen.getByText('Beta')).toBeInTheDocument();
    expect(screen.getAllByText(/SAR|ر\.س/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/\$/).length).toBeGreaterThan(0);
    expect(screen.queryByText(/total/i)).not.toBeInTheDocument();
  });

  it('writes a dash, not zero, for the average of a store that sold nothing', async () => {
    state.rollup = {
      revenue_source: 'salla',
      from: '2026-09-16',
      until: '2026-10-15',
      stores: [
        store({
          store_name: 'Quiet',
          orders: 0,
          revenue_minor: 0,
          net_revenue_minor: 0,
          average_order_minor: null,
          attributed_revenue_minor: 0,
        }),
      ],
    };
    await renderWithProviders(<StoresRollupPage />);

    const row = (await screen.findByText('Quiet')).closest('tr');

    expect(row?.textContent).toContain('—');
  });

  it('explains an agency with no stores', async () => {
    state.rollup = { revenue_source: 'salla', from: '', until: '', stores: [] };
    await renderWithProviders(<StoresRollupPage />);

    expect(await screen.findByText('No stores yet')).toBeInTheDocument();
  });
});
