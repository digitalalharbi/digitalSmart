import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router';
import type { MetricValue } from '@/shared/metrics';
import { renderWithProviders } from '@/test/render';
import { SharedReportPage } from './SharedReportPage';
import type { RenderedReport, RenderedSection } from './types';
import type { SharedReportResult } from './sharedApi';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

const state = vi.hoisted(() => ({
  result: null as SharedReportResult | null,
  failsWith: null as Error | null,
  calls: 0,
  seen: [] as { token: string; filters: unknown; refine: boolean }[],
}));

vi.mock('./sharedApi', () => ({
  isOutOfScope: (error: unknown) => error instanceof Error && error.message === 'out of scope',
  fetchSharedReport: (token: string, filters: unknown, refine: boolean) => {
    state.calls += 1;
    state.seen.push({ token, filters, refine });

    if (state.failsWith !== null) {
      return Promise.reject(state.failsWith);
    }

    return Promise.resolve({ ...(state.result as SharedReportResult), token });
  },
}));

function metricValue(overrides: Partial<MetricValue> = {}): MetricValue {
  return {
    metric: 'spend',
    label: 'Spend',
    value: 125_000,
    format: 'currency' as const,
    precision: 2,
    is_minor_units: true,
    currency: 'SAR',
    source: 'synced' as const,
    source_label: 'Synced',
    requires_label: false,
    unavailable_reason: null,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
    ...overrides,
  };
}

function section(): RenderedSection {
  return {
    kind: 'scorecard',
    title: 'Headline figures',
    metrics: ['spend'],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    caveats: [],
    currency: 'SAR',
    totals: {
      values: { spend: metricValue() },
      source: 'synced',
      currency: 'SAR',
      row_count: 30,
      is_empty: false,
    },
    series: [],
    rows: [],
  };
}

function rendered(overrides: Partial<RenderedReport> = {}): RenderedReport {
  return {
    id: 'report-1',
    name: 'Monthly performance',
    description: 'Written to be read by the client.',
    client_name: 'Alhambra',
    period: { from: '2026-09-08', until: '2026-10-07', days: 30, is_incomplete: false },
    comparison: {
      mode: 'none',
      label: 'No comparison',
      from: null,
      until: null,
      was_requested: false,
      is_comparable: false,
      reason: null,
      code: null,
    },
    audience: 'client_safe',
    mode: 'live',
    mode_label: 'Live — recalculated every time it is opened',
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    /* A client-safe render never carries one. The fixture says so explicitly rather than by omission. */
    internal_note: null,
    rendered_at: '2026-10-08T09:00:00+00:00',
    sections: [section()],
    ...overrides,
  };
}

function result(overrides: Partial<SharedReportResult> = {}): SharedReportResult {
  return {
    state: 'active',
    message: null,
    mode: 'live',
    recipient_label: 'Alhambra marketing team',
    report: rendered(),
    ...overrides,
  };
}

async function open(token = 'a'.repeat(96), search = ''): Promise<void> {
  await renderWithProviders(
    <Routes>
      <Route path="/shared-reports/:token" element={<SharedReportPage />} />
    </Routes>,
    { route: `/shared-reports/${token}${search}` },
  );
}

beforeEach(() => {
  state.result = result();
  state.failsWith = null;
  state.calls = 0;
  state.seen = [];
});

describe('SharedReportPage', () => {
  it('renders the report with its period, source and timestamp', async () => {
    await open();

    expect(await screen.findByRole('heading', { name: 'Monthly performance' })).toBeInTheDocument();
    expect(screen.getByText(/2026-09-08 – 2026-10-07/)).toBeInTheDocument();
    expect(screen.getByText(/Figures as at/)).toBeInTheDocument();
    expect(screen.getByText('Live — recalculated every time it is opened')).toBeInTheDocument();
  });

  it('carries no navigation out of the page', async () => {
    await open();

    await screen.findByRole('heading', { name: 'Monthly performance' });

    /*
     * A visitor has nowhere to go from here. A chrome full of controls that lead to a sign-in screen would invite
     * them to try, so the only two are the language and the theme.
     */
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('offers no export, because there is no endpoint behind one', async () => {
    await open();

    await screen.findByRole('heading', { name: 'Monthly performance' });

    expect(screen.queryByText('Download CSV')).not.toBeInTheDocument();
  });

  it('names who the link was prepared for', async () => {
    await open();

    expect(await screen.findByText('Prepared for Alhambra marketing team.')).toBeInTheDocument();
  });

  it('says an expired link expired, rather than that it was not found', async () => {
    state.result = result({
      state: 'expired',
      message: 'This link has expired. Ask whoever sent it for a new one.',
      mode: null,
      recipient_label: null,
      report: null,
    });

    await open();

    expect(await screen.findByText(/This link has expired/)).toBeInTheDocument();
    expect(screen.queryByText(/not found/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Monthly performance' })).not.toBeInTheDocument();
  });

  it('says a turned-off link was turned off', async () => {
    state.result = result({
      state: 'revoked',
      message: 'This link has been turned off. Ask whoever sent it for a new one.',
      mode: null,
      recipient_label: null,
      report: null,
    });

    await open();

    expect(await screen.findByText(/has been turned off/)).toBeInTheDocument();
  });

  it('tells a reader a failed load is a connection problem, not an expired link', async () => {
    state.failsWith = new Error('Network Error');

    await open();

    expect(await screen.findByText(/connection problem, not an expired link/)).toBeInTheDocument();

    /*
     * One attempt, not two. A retry re-records the view, and a link's open count is read as "the client looked at
     * it" — inflating it to paper over a flaky connection is a figure this product would be lying with.
     */
    expect(state.calls).toBe(1);
  });
});

const OPTIONS = {
  can_filter: true,
  available: {
    platforms: [
      { key: 'meta', label: 'Meta' },
      { key: 'google_ads', label: 'Google Ads' },
    ],
    campaigns: [],
    stores: [],
    period: { from: '2026-09-08', until: '2026-10-07' },
  },
  applied: null,
};

describe('SharedReportPage — narrowing', () => {
  it('offers filters only where the server says the link can be narrowed', async () => {
    state.result = result({ filters: OPTIONS });
    await open();

    expect(await screen.findByRole('button', { name: /Filters/ })).toBeInTheDocument();
  });

  it('offers none on a frozen report, or on a report with nothing to choose between', async () => {
    state.result = result({
      mode: 'snapshot',
      filters: { can_filter: false, available: null, applied: null },
    });
    await open();
    await screen.findByRole('heading', { name: 'Monthly performance' });

    expect(screen.queryByRole('button', { name: /Filters/ })).not.toBeInTheDocument();
  });

  it('reads the filters out of the address, counts the open as the visit, and refines without another', async () => {
    state.result = result({ filters: OPTIONS });
    await open('a'.repeat(96), '?platform=meta');

    expect(await screen.findByRole('button', { name: 'Remove Meta' })).toBeInTheDocument();
    expect(state.seen[0]).toMatchObject({ refine: false });
    expect((state.seen[0]?.filters as { platforms: string[] }).platforms).toEqual(['meta']);

    await userEvent.click(screen.getByRole('button', { name: 'Remove Meta' }));

    await waitFor(() => {
      expect(state.seen.length).toBeGreaterThan(1);
    });
    expect(state.seen[state.seen.length - 1]).toMatchObject({ refine: true });
  });

  it('clears every filter in one action', async () => {
    state.result = result({ filters: OPTIONS });
    await open('a'.repeat(96), '?platform=meta,google_ads');

    await userEvent.click(await screen.findByRole('button', { name: 'Clear filters' }));

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'Remove Meta' })).not.toBeInTheDocument();
    });
  });

  it('drops a selection the server refuses, and says the whole report is shown', async () => {
    state.result = result({ filters: OPTIONS });
    state.failsWith = new Error('out of scope');
    await open('a'.repeat(96), '?platform=tiktok');

    // The refusal is answered, then the next read — with no filters — succeeds.
    await waitFor(() => {
      expect(state.calls).toBeGreaterThan(0);
    });
    state.failsWith = null;

    expect(
      await screen.findByText(/not part of this report, so the whole report is shown/),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove TikTok' })).not.toBeInTheDocument();
  });

  it('says there is no data for a selection that matches nothing, rather than showing zeroes', async () => {
    const empty = rendered({ sections: [{ ...section(), is_empty: true }] });
    state.result = result({ report: empty, filters: OPTIONS });
    await open('a'.repeat(96), '?platform=meta');

    expect(await screen.findByText('No data for this selection')).toBeInTheDocument();
    expect(screen.queryByText('SAR 1,250.00')).not.toBeInTheDocument();
  });
});
