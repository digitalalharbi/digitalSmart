import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { CampaignsPage } from './CampaignsPage';
import type * as AnalyticsFeature from '@/features/analytics';
import type {
  AnalyticsPayload,
  BreakdownRowPayload,
  CataloguePayload,
  MetricValue,
} from '@/shared/metrics';

const fetchAnalytics = vi.hoisted(() => vi.fn());
const fetchCatalogue = vi.hoisted(() => vi.fn());

/*
 * The feature's own components are kept; only its two data hooks are replaced, so
 * the drill-down is exercised through the real FilterBar and BreakdownTable rather
 * than stand-ins.
 */
vi.mock('@/features/analytics', async () => {
  const actual = await vi.importActual<typeof AnalyticsFeature>('@/features/analytics');
  const { useQuery } = await import('@tanstack/react-query');

  return {
    ...actual,
    useAnalytics: (query: unknown) =>
      useQuery<AnalyticsPayload>({
        queryKey: ['analytics', query],
        queryFn: () => fetchAnalytics(query) as Promise<AnalyticsPayload>,
      }),
    useMetricCatalogue: () => {
      const result = useQuery<CataloguePayload>({
        queryKey: ['catalogue'],
        queryFn: () => fetchCatalogue() as Promise<CataloguePayload>,
      });

      const definitions = new Map(
        (result.data?.metrics ?? []).map((metric) => [metric.value, metric]),
      );

      return { ...result, definitions };
    },
  };
});

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

const NAMES = ['spend', 'roas', 'conversions', 'cpa', 'ctr', 'impressions', 'clicks', 'cpc'];

function metric(name: string): MetricValue {
  return {
    metric: name,
    label: name.toUpperCase(),
    value: 500,
    format: 'count',
    precision: 0,
    is_minor_units: false,
    currency: null,
    source: 'sandbox',
    source_label: 'Sandbox',
    requires_label: true,
    unavailable_reason: null,
    comparison_value: 450,
    change_ratio: 0.11,
    change_is_good: true,
    lower_is_better: false,
  };
}

function values(): Record<string, MetricValue> {
  return Object.fromEntries(NAMES.map((name) => [name, metric(name)]));
}

function row(dimension: string, key: string, label: string): BreakdownRowPayload {
  return {
    dimension,
    key,
    label,
    external_id: `ext-${key}`,
    status: 'active',
    parent_key: null,
    source: 'sandbox',
    currency: 'SAR',
    row_count: 28,
    values: values(),
  };
}

function payload(dimension: string, rows: BreakdownRowPayload[]): AnalyticsPayload {
  return {
    range: { start: '2026-09-07', end: '2026-10-04', days: 28 },
    compare_to: null,
    metrics: NAMES,
    source: 'sandbox',
    source_label: 'Sandbox',
    requires_label: true,
    is_empty: false,
    summary: { source: 'sandbox', currency: 'SAR', row_count: 28, values: values() },
    series: [
      { date: '2026-10-04', source: 'sandbox', currency: 'SAR', row_count: 4, values: values() },
    ],
    breakdown: rows,
    dimension,
  };
}

const catalogue: CataloguePayload = {
  metrics: NAMES.map((value) => ({
    value,
    label: value.toUpperCase(),
    format: 'count',
    precision: 0,
    is_derived: false,
    is_monetary: false,
    additive_across_entities: true,
    lower_is_better: false,
    depends_on: [],
  })),
  dimensions: [],
  providers: [
    { value: 'meta', label: 'Meta Ads' },
    { value: 'google_ads', label: 'Google Ads' },
  ],
  sources: [],
};

describe('CampaignsPage', () => {
  beforeEach(() => {
    fetchAnalytics.mockReset();
    fetchCatalogue.mockReset();
    fetchCatalogue.mockResolvedValue(catalogue);
  });

  it('starts at platform level', async () => {
    fetchAnalytics.mockResolvedValue(payload('provider', [row('provider', 'meta', 'Meta Ads')]));
    await renderWithProviders(<CampaignsPage />);

    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenCalledWith(
        expect.objectContaining({ dimension: 'provider' }),
      );
    });

    const trail = screen.getByRole('navigation', { name: /Drill-down trail/i });
    expect(within(trail).getByRole('button', { current: 'page' })).toHaveTextContent(
      'All platforms',
    );
  });

  it('drills from a platform into its campaigns, narrowing the whole surface', async () => {
    fetchAnalytics.mockResolvedValue(payload('provider', [row('provider', 'meta', 'Meta Ads')]));
    await renderWithProviders(<CampaignsPage />);

    const table = await screen.findByRole('table');
    await userEvent.click(within(table).getByText('Meta Ads'));

    /*
     * The filter travels with the drill-down, so the scorecard and the chart above
     * narrow too. A breadcrumb that does not change the figures above it is
     * decoration.
     */
    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenCalledWith(
        expect.objectContaining({ dimension: 'campaign', providers: ['meta'] }),
      );
    });
  });

  it('lets a reader climb back out through the trail', async () => {
    fetchAnalytics.mockResolvedValue(payload('provider', [row('provider', 'meta', 'Meta Ads')]));
    await renderWithProviders(<CampaignsPage />);

    const table = await screen.findByRole('table');
    await userEvent.click(within(table).getByText('Meta Ads'));

    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenCalledWith(
        expect.objectContaining({ dimension: 'campaign' }),
      );
    });

    const trail = screen.getByRole('navigation', { name: /Drill-down trail/i });
    await userEvent.click(within(trail).getByRole('button', { name: /All platforms/ }));

    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenLastCalledWith(
        expect.objectContaining({ dimension: 'provider' }),
      );
    });
  });

  it('stops offering a drill-down once it reaches an ad', async () => {
    /*
     * The level comes from how far the reader has gone, not from the response, so
     * reaching the bottom means actually walking down: platform → campaign → ad
     * group → ad.
     */
    fetchAnalytics.mockImplementation((query: { dimension: string }) =>
      Promise.resolve(payload(query.dimension, [row(query.dimension, 'k', 'A row')])),
    );

    await renderWithProviders(<CampaignsPage />);

    for (const level of ['provider', 'campaign', 'ad_group']) {
      await waitFor(() => {
        expect(fetchAnalytics).toHaveBeenLastCalledWith(
          expect.objectContaining({ dimension: level }),
        );
      });

      // The hint is present at every level that has children.
      expect(await screen.findByText(/Select a row to open/i)).toBeInTheDocument();

      const table = await screen.findByRole('table');
      await userEvent.click(within(table).getByText('A row'));
    }

    await waitFor(() => {
      expect(fetchAnalytics).toHaveBeenLastCalledWith(expect.objectContaining({ dimension: 'ad' }));
    });

    // An ad has nothing below it, so neither the hint nor the row affordance is
    // offered — rather than inviting a click that would do nothing.
    await waitFor(() => {
      expect(screen.queryByText(/Select a row to open/i)).not.toBeInTheDocument();
    });
  });

  it('warns that the figures are sandbox', async () => {
    fetchAnalytics.mockResolvedValue(payload('provider', [row('provider', 'meta', 'Meta Ads')]));
    await renderWithProviders(<CampaignsPage />);

    expect(await screen.findByRole('status')).toHaveTextContent(/Sandbox/);
  });
});
