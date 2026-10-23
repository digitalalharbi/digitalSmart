import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { LinkPerformancePanel } from './LinkPerformancePanel';
import type { LinkPerformance } from './types';

const state: { data: LinkPerformance } = { data: undefined as unknown as LinkPerformance };
const allocate = vi.fn();

vi.mock('./api', () => ({
  useLinkPerformanceQuery: (_id: string, _days: number, withAllocation: boolean) => {
    allocate(withAllocation);

    return { isPending: false, isError: false, data: state.data };
  },
}));

const none = { minor: null, currency: null, reason: 'No spend can be credited to this link.' };

function performance(over: Partial<LinkPerformance> = {}): LinkPerformance {
  return {
    link_id: 'l1',
    from: '2026-09-24',
    to: '2026-10-23',
    binding_level: 'campaign',
    funnel: { stages: [] },
    spend: {
      basis: 'UNAVAILABLE',
      minor: null,
      currency: null,
      level: 'campaign',
      reason:
        'Another link is bound to the same campaign; its spend cannot be assigned to one of them.',
      days_reported: null,
      allocation: null,
    },
    costs: { cpc: none, cpl: none, cac: none },
    revenue: {
      lead_value: {
        minor: null,
        currency: null,
        count: null,
        reason: 'No won lead from this link has a value recorded.',
        evidence: 'recorded_by_person',
        roas: { value: null, reason: 'No spend can be credited to this link.' },
      },
    },
    ...over,
  };
}

describe('LinkPerformancePanel', () => {
  it('says why there is no spend and never writes the missing costs as zero', async () => {
    state.data = performance();
    await renderWithProviders(<LinkPerformancePanel linkId="l1" />);

    expect(await screen.findByText('Not available')).toBeInTheDocument();
    expect(screen.getByText(/Another link is bound to the same campaign/)).toBeInTheDocument();
    expect(screen.queryByText(/^0([.,]00)?$/)).not.toBeInTheDocument();
    expect(screen.getAllByText('Not reported').length).toBeGreaterThan(3);
  });

  it('labels an allocation with its method, share and confidence', async () => {
    state.data = performance({
      spend: {
        basis: 'ALLOCATED',
        minor: 7500,
        currency: 'SAR',
        level: 'campaign',
        reason: null,
        days_reported: 14,
        allocation: { method: 'tracked_clicks', share: 0.75, coverage: 1, confidence: 'low' },
      },
    });
    await renderWithProviders(<LinkPerformancePanel linkId="l1" />);

    expect(await screen.findByText('Allocated')).toBeInTheDocument();
    expect(
      screen.getByText(/Allocated by tracked clicks: 75% share, low confidence/),
    ).toBeInTheDocument();
  });

  it('asks for the allocation only when the reader chooses it', async () => {
    state.data = performance();
    await renderWithProviders(<LinkPerformancePanel linkId="l1" />);
    await userEvent.click(await screen.findByRole('checkbox', { name: /Allocate shared spend/ }));

    expect(allocate).toHaveBeenCalledWith(true);
  });
});
