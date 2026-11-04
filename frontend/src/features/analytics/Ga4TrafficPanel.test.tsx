import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { Ga4TrafficPanel } from './Ga4TrafficPanel';
import { lastDays } from './ga4Traffic';
import type * as ClientModule from '@/shared/api/client';
import type { Ga4TrafficResponse } from './ga4Traffic';

const state = vi.hoisted(() => ({
  response: null as Ga4TrafficResponse | null,
  failure: null as Error | null,
  requests: [] as string[],
}));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof ClientModule>();

  return {
    ...actual,
    api: {
      get: (url: string) => {
        state.requests.push(url);

        return state.failure === null
          ? Promise.resolve({ data: state.response })
          : Promise.reject(state.failure);
      },
    },
  };
});

const figures = {
  sessions: 1000,
  engaged_sessions: 600,
  page_views: 2500,
  engagement_seconds: 50000,
  engagement_rate: 0.6,
  engagement_seconds_per_session: 50,
};

function payload(overrides: Partial<Ga4TrafficResponse['data']> = {}): Ga4TrafficResponse {
  return {
    data: {
      period: { from: '2026-10-01', until: '2026-10-28' },
      previous_period: { from: '2026-09-03', until: '2026-09-30' },
      group: 'channel',
      totals: figures,
      previous: { ...figures, sessions: null },
      change: { sessions: null, engaged_sessions: 0.1, page_views: null },
      trend: [],
      rows: [
        {
          name: 'Paid Social',
          channel_group: 'Paid Social',
          source: null,
          medium: null,
          ...figures,
          sessions: 620,
          share_of_sessions: 0.62,
        },
        {
          name: 'Direct',
          channel_group: 'Direct',
          source: null,
          medium: null,
          ...figures,
          sessions: 380,
          engaged_sessions: null,
          engagement_rate: null,
          share_of_sessions: 0.38,
        },
      ],
      quality: {
        source: 'sandbox',
        source_label: 'Sandbox',
        is_sandbox: true,
        time_zones: ['Asia/Riyadh'],
        days_in_period: 28,
        days_present: 2,
        first_day: '2026-10-27',
        last_day: '2026-10-28',
        is_complete: false,
        no_data: false,
      },
      sources: [],
      users: { available: false, reason: 'not additive' },
      ...overrides,
    },
    meta: { page: 1, per_page: 10, total: 2 },
  };
}

describe('lastDays', () => {
  it('ends yesterday and covers whole days', () => {
    expect(lastDays(7, new Date(2026, 9, 29, 15))).toEqual({
      from: '2026-10-22',
      until: '2026-10-28',
    });
  });
});

describe('Ga4TrafficPanel', () => {
  beforeEach(() => {
    state.response = payload();
    state.failure = null;
    state.requests = [];
  });

  it('shows the figures, says they are sandbox, and says what days they cover', async () => {
    await renderWithProviders(<Ga4TrafficPanel days={28} clientId={null} />);

    expect(await screen.findByText('Website traffic (Google Analytics 4)')).toBeInTheDocument();
    expect(screen.getByText(/2 of 28 days in this period have stored data/)).toBeInTheDocument();
    expect(screen.getAllByText('1,000').length).toBeGreaterThan(0);
    expect(screen.getByText(/Users are not shown/)).toBeInTheDocument();
    // The sandbox notice comes before the numbers.
    expect(screen.getByText(/sandbox/i)).toBeInTheDocument();
  });

  it('writes "Not reported" for a missing figure, never zero', async () => {
    await renderWithProviders(<Ga4TrafficPanel days={28} clientId={null} />);

    const row = (await screen.findByText('Direct')).closest('tr');
    expect(row).not.toBeNull();
    expect(within(row as HTMLElement).getAllByText('Not reported').length).toBe(2);
  });

  it('asks the server for the next page and the other grouping, and scopes by client', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<Ga4TrafficPanel days={28} clientId="c1" />);

    await screen.findByText('Paid Social');
    expect(state.requests[0]).toContain('client_id=c1');
    expect(state.requests[0]).toContain('per_page=10');

    await user.selectOptions(screen.getByLabelText('Group by'), 'source_medium');
    await screen.findByText('Source / medium', { selector: 'th' });
    expect(state.requests.at(-1)).toContain('group=source_medium');
    expect(state.requests.at(-1)).toContain('page=1');
  });

  it('says there is nothing stored instead of drawing zeroes', async () => {
    state.response = payload({
      rows: [],
      totals: { ...figures, sessions: null },
      quality: { ...payload().data.quality, no_data: true, days_present: 0 },
    });
    await renderWithProviders(<Ga4TrafficPanel days={28} clientId={null} />);

    expect(await screen.findByText('No GA4 traffic stored for this period')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });

  it('shows the failure with a retry', async () => {
    state.failure = new Error('boom');
    await renderWithProviders(<Ga4TrafficPanel days={28} clientId={null} />);

    expect(await screen.findByText('The traffic could not be loaded.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });
});
