import { screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { Ga4FunnelPanel } from './Ga4FunnelPanel';
import type { Ga4Funnel } from './ga4Traffic';
import type * as ClientModule from '@/shared/api/client';

const state = vi.hoisted(() => ({
  response: null as { data: Ga4Funnel } | null,
  failure: null as Error | null,
}));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof ClientModule>();

  return {
    ...actual,
    api: {
      get: () =>
        state.failure === null
          ? Promise.resolve({ data: state.response })
          : Promise.reject(state.failure),
    },
  };
});

function funnel(overrides: Partial<Ga4Funnel> = {}): { data: Ga4Funnel } {
  return {
    data: {
      period: { from: '2026-10-01', until: '2026-10-28' },
      stages: [
        { event: 'view_item', count: 6000, ratio_from_previous: null },
        { event: 'add_to_cart', count: 1200, ratio_from_previous: 0.2 },
        { event: 'begin_checkout', count: null, ratio_from_previous: null },
        { event: 'purchase', count: 90, ratio_from_previous: null },
      ],
      days_present: 4,
      days_in_period: 28,
      source: 'sandbox',
      time_zones: ['Asia/Riyadh'],
      sources: [],
      note: 'x',
      ...overrides,
    },
  };
}

describe('Ga4FunnelPanel', () => {
  beforeEach(() => {
    state.response = funnel();
    state.failure = null;
  });

  it('shows each stage as events, the ratio to the previous stage, and says it is not a conversion rate', async () => {
    await renderWithProviders(<Ga4FunnelPanel days={28} clientId={null} />);

    expect(await screen.findByText('Shopping events (Google Analytics 4)')).toBeInTheDocument();
    expect(screen.getByText('6,000')).toBeInTheDocument();
    expect(screen.getByText(/20\.0% of the previous stage \(events\)/)).toBeInTheDocument();
    expect(screen.getByText(/not a conversion rate/)).toBeInTheDocument();
    expect(screen.getByText(/sandbox/i)).toBeInTheDocument();
  });

  it('writes "Not reported" for a stage the property never reported, and draws no ratio across it', async () => {
    await renderWithProviders(<Ga4FunnelPanel days={28} clientId={null} />);

    expect(await screen.findByText('Not reported')).toBeInTheDocument();
    // The purchase stage follows the gap, so it carries no ratio.
    expect(screen.getAllByText(/of the previous stage/)).toHaveLength(1);
  });

  it('says nothing was reported instead of drawing zero-length bars', async () => {
    state.response = funnel({
      stages: [
        { event: 'view_item', count: null, ratio_from_previous: null },
        { event: 'add_to_cart', count: null, ratio_from_previous: null },
        { event: 'begin_checkout', count: null, ratio_from_previous: null },
        { event: 'purchase', count: null, ratio_from_previous: null },
      ],
      source: null,
    });
    await renderWithProviders(<Ga4FunnelPanel days={28} clientId={null} />);

    expect(await screen.findByText(/has reported none of the shopping events/)).toBeInTheDocument();
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
  });

  it('shows the failure with a retry', async () => {
    state.failure = new Error('boom');
    await renderWithProviders(<Ga4FunnelPanel days={28} clientId={null} />);

    expect(await screen.findByText('The shopping events could not be loaded.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });
});
