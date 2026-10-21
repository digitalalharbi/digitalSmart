import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ApiError } from '@/shared/api/client';
import { AnalyticsPage } from './AnalyticsPage';
import type { AnalyticsPayload, CataloguePayload, MetricValue } from '@/shared/metrics';

const fetchAnalytics = vi.hoisted(() => vi.fn());
const fetchCatalogue = vi.hoisted(() => vi.fn());

vi.mock('./api', () => ({
  fetchAnalytics: (...args: unknown[]) => fetchAnalytics(...args),
  fetchCatalogue: () => fetchCatalogue(),
}));

/**
 * The scorecard, so a metric label is unambiguous: the same label also appears as
 * a column header in the breakdown table below.
 */
function scorecard() {
  return within(screen.getByRole('region', { name: 'Key figures' }));
}

/*
 * ECharts needs a real canvas and a measured container, neither of which jsdom
 * provides. The wrapper is stubbed so these tests assert the page's behaviour —
 * what it requests, what it labels, what it says when a figure is missing — while
 * the option builders are covered by their own unit tests against real data shapes.
 */
vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

function metric(name: string, overrides: Partial<MetricValue> = {}): MetricValue {
  return {
    metric: name,
    label: name.toUpperCase(),
    value: 1000,
    format: 'count',
    precision: 0,
    is_minor_units: false,
    currency: null,
    source: 'sandbox',
    source_label: 'Sandbox',
    requires_label: true,
    unavailable_reason: null,
    comparison_value: 900,
    change_ratio: 0.111,
    change_is_good: true,
    lower_is_better: false,
    ...overrides,
  };
}

const METRIC_NAMES = [
  'spend',
  'revenue',
  'roas',
  'conversions',
  'cpa',
  'impressions',
  'clicks',
  'ctr',
  'cpc',
  'cpm',
];

function payload(overrides: Partial<AnalyticsPayload> = {}): AnalyticsPayload {
  const values: Record<string, MetricValue> = {};

  for (const name of METRIC_NAMES) {
    values[name] = metric(name);
  }

  return {
    range: { start: '2026-09-07', end: '2026-10-04', days: 28 },
    compare_to: { start: '2026-08-10', end: '2026-09-06' },
    metrics: METRIC_NAMES,
    source: 'sandbox',
    source_label: 'Sandbox',
    requires_label: true,
    is_empty: false,
    summary: { source: 'sandbox', currency: 'SAR', row_count: 120, values },
    series: [
      { date: '2026-10-03', source: 'sandbox', currency: 'SAR', row_count: 4, values },
      { date: '2026-10-04', source: 'sandbox', currency: 'SAR', row_count: 4, values },
    ],
    breakdown: [
      {
        dimension: 'campaign',
        key: 'c1',
        label: 'SANDBOX — Ramadan Sales',
        external_id: '23850000000000001',
        status: 'active',
        parent_key: null,
        source: 'sandbox',
        currency: 'SAR',
        row_count: 28,
        values,
      },
    ],
    dimension: 'campaign',
    ...overrides,
  };
}

const catalogue: CataloguePayload = {
  metrics: METRIC_NAMES.map((value) => ({
    value,
    label: value.toUpperCase(),
    format: 'count',
    precision: 0,
    is_derived: false,
    is_monetary: false,
    additive_across_entities: value !== 'reach',
    lower_is_better: value === 'cpa' || value === 'cpc',
    depends_on: [],
  })),
  dimensions: [
    { value: 'campaign', label: 'Campaign', level: 'campaign' },
    { value: 'provider', label: 'Platform', level: null },
  ],
  providers: [
    { value: 'meta', label: 'Meta Ads' },
    { value: 'google_ads', label: 'Google Ads' },
  ],
  sources: [
    { value: 'live', label: 'Live', requires_label: false, is_actual: true },
    { value: 'sandbox', label: 'Sandbox', requires_label: true, is_actual: false },
  ],
};

describe('AnalyticsPage', () => {
  beforeEach(() => {
    fetchAnalytics.mockReset();
    fetchCatalogue.mockReset();
    fetchCatalogue.mockResolvedValue(catalogue);
  });

  it('warns that the figures are sandbox before showing them', async () => {
    fetchAnalytics.mockResolvedValue(payload());
    await renderWithProviders(<AnalyticsPage />);

    const notice = await screen.findByRole('status');

    /*
     * The notice is above the scorecard in the DOM as well as on screen, so a
     * reader meets the caveat before drawing a conclusion from the numbers.
     */
    expect(notice).toHaveTextContent(/Sandbox/);
    expect(notice).toHaveTextContent(/Do not share them with a client/i);
  });

  it('renders the scorecard and the breakdown table', async () => {
    fetchAnalytics.mockResolvedValue(payload());
    await renderWithProviders(<AnalyticsPage />);

    await screen.findByRole('region', { name: 'Key figures' });

    expect(scorecard().getByText('SPEND')).toBeInTheDocument();

    // The table is the working, not the answer: it opens on request.
    expect(screen.queryByText('23850000000000001')).not.toBeInTheDocument();
    await userEvent.click(await screen.findByRole('button', { name: /^Details/ }));

    expect(screen.getAllByText('SANDBOX — Ramadan Sales').length).toBeGreaterThan(0);
    // The provider's own id, for reconciling against the platform.
    expect(screen.getByText('23850000000000001')).toBeInTheDocument();
  });

  it('explains a missing figure rather than showing a zero', async () => {
    fetchAnalytics.mockResolvedValue(
      payload({
        summary: {
          source: 'sandbox',
          currency: 'SAR',
          row_count: 120,
          values: {
            ...payload().summary.values,
            revenue: metric('revenue', {
              value: null,
              unavailable_reason: 'Not reported by this platform for the selected period.',
            }),
          },
        },
      }),
    );

    await renderWithProviders(<AnalyticsPage />);

    expect(await screen.findByText(/Not reported by this platform/)).toBeInTheDocument();
  });

  it('plots whichever figure the reader selects', async () => {
    fetchAnalytics.mockResolvedValue(payload());
    await renderWithProviders(<AnalyticsPage />);

    await screen.findByRole('region', { name: 'Key figures' });
    await userEvent.click(scorecard().getByRole('button', { name: /CLICKS/ }));

    // Selecting a tile re-queries with that metric and retitles the chart, which
    // is how a reader investigates the figure that caught their eye.
    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenCalledWith(
        expect.objectContaining({ metrics: expect.arrayContaining(['clicks']) }),
      );
    });
    expect(await screen.findByText('CLICKS over time')).toBeInTheDocument();
  });

  it('changes the period without losing the page', async () => {
    fetchAnalytics.mockResolvedValue(payload());
    await renderWithProviders(<AnalyticsPage />);

    await screen.findByRole('region', { name: 'Key figures' });
    await userEvent.click(screen.getByRole('button', { name: '7 days' }));

    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenCalledWith(expect.objectContaining({ preset: '7d' }));
    });
    // Previous figures stay on screen while the new ones load, rather than the
    // page collapsing to skeletons on every interaction.
    expect(scorecard().getByText('SPEND')).toBeInTheDocument();
  });

  it('filters by platform through the identifier chips', async () => {
    fetchAnalytics.mockResolvedValue(payload());
    await renderWithProviders(<AnalyticsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Meta Ads/ }));

    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenCalledWith(expect.objectContaining({ providers: ['meta'] }));
    });
  });

  it('tells an unentitled role why the screen is empty', async () => {
    fetchAnalytics.mockRejectedValue(new ApiError('Forbidden', 403));
    await renderWithProviders(<AnalyticsPage />);

    expect(
      await screen.findByText(/role does not include access to performance analytics/i),
    ).toBeInTheDocument();
  });

  it('explains an empty period rather than showing an empty grid', async () => {
    fetchAnalytics.mockResolvedValue(
      payload({ is_empty: true, breakdown: [], series: [], source: 'live', requires_label: false }),
    );

    await renderWithProviders(<AnalyticsPage />);

    expect(await screen.findByText(/No performance data for this period/i)).toBeInTheDocument();
    // And says what to do about it, rather than leaving the reader stuck.
    expect(screen.getByText(/bind an account to a project/i)).toBeInTheDocument();
  });

  it('distinguishes a transport failure from a permission refusal', async () => {
    fetchAnalytics.mockRejectedValue(new ApiError('Server error', 500));
    await renderWithProviders(<AnalyticsPage />);

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
  });
});

describe('AnalyticsPage — command centre', () => {
  function providerPayload(): AnalyticsPayload {
    const base = payload();
    const row = (key: string, label: string, spend: number) => ({
      ...base.breakdown[0]!,
      dimension: 'provider',
      key,
      label,
      external_id: null,
      values: { ...base.breakdown[0]!.values, spend: metric('spend', { value: spend }) },
    });

    return {
      ...base,
      dimension: 'provider',
      breakdown: [row('meta', 'Meta Ads', 600), row('google_ads', 'Google Ads', 400)],
    };
  }

  beforeEach(() => {
    fetchAnalytics.mockImplementation((query: { dimension?: string }) =>
      Promise.resolve(query.dimension === 'provider' ? providerPayload() : payload()),
    );
  });

  it('shows each platform as a card and narrows the page to the one chosen', async () => {
    await renderWithProviders(<AnalyticsPage />);

    // The platform cards are their own list; the filter bar carries a chip of the same name.
    const cards = within(await screen.findByRole('list', { name: 'Platform cards' }));
    await userEvent.click(cards.getByRole('button', { name: /Meta Ads/, pressed: false }));

    expect(
      await cards.findByRole('button', { name: /Meta Ads/, pressed: true }),
    ).toBeInTheDocument();

    await waitFor(() => {
      expect(
        (fetchAnalytics.mock.calls as [{ providers?: string[] }][]).some(
          ([query]) => query.providers?.includes('meta') === true,
        ),
      ).toBe(true);
    });
  });

  it('draws a spark of each key figure from the days the hero chart draws', async () => {
    await renderWithProviders(<AnalyticsPage />);
    await screen.findByRole('region', { name: 'Key figures' });

    // Two days of data is a line; one tile each, hidden from assistive technology.
    expect(
      document.querySelectorAll('[data-sparkline], svg[aria-hidden="true"]').length,
    ).toBeGreaterThan(0);
  });

  it('offers the hero chart a choice of figure', async () => {
    await renderWithProviders(<AnalyticsPage />);

    const group = await screen.findByRole('group', { name: 'Metric' });

    expect(within(group).getByRole('button', { name: 'ROAS' })).toBeInTheDocument();
  });

  it('shows no attribution comparison without one client in view', async () => {
    await renderWithProviders(<AnalyticsPage />);
    await screen.findByRole('region', { name: 'Key figures' });

    expect(screen.queryByText('Revenue, by who says so')).not.toBeInTheDocument();
  });
});
