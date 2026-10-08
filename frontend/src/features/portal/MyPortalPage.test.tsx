import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { MetricValue } from '@/shared/metrics';
import { renderWithProviders } from '@/test/render';
import { MyPortalPage } from './MyPortalPage';
import type { PortalEntry, PortalPerformance } from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

/* The client's intake thread is the agency's own component — see `PortalRequestsPanel`. */
vi.mock('@/features/requests', () => ({
  RequestsPage: () => <div data-testid="requests">Your requests</div>,
}));

const state = vi.hoisted(() => ({
  entries: [] as PortalEntry[],
  performance: null as PortalPerformance | null,
  reports: [] as unknown[],
}));

vi.mock('./api', () => ({
  fetchMyPortals: () => Promise.resolve(state.entries),
  fetchPortalPerformance: () => Promise.resolve(state.performance),
  fetchPortalReports: () => Promise.resolve(state.reports),
  fetchPortalReport: () => Promise.resolve(null),
  fetchPortalRevenue: () => Promise.resolve({ analytics: null, available: [] }),
  fetchPortalTracking: () =>
    Promise.resolve({ sources: [], definitions: [], issues: [], window_days: 14 }),
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
    source: 'sandbox' as const,
    source_label: 'Sandbox',
    requires_label: true,
    unavailable_reason: null,
    comparison_value: 100_000,
    change_ratio: 0.25,
    change_is_good: false,
    lower_is_better: false,
    ...overrides,
  };
}

function entry(overrides: Partial<PortalEntry> = {}): PortalEntry {
  return {
    client_id: 'client-1',
    client_name: 'Alhambra',
    state: 'active',
    message: null,
    modules: [{ module: 'performance', label: 'Performance' }],
    always_available: [{ module: 'requests', label: 'Requests' }],
    is_preview: false,
    ...overrides,
  };
}

function performance(overrides: Partial<PortalPerformance> = {}): PortalPerformance {
  return {
    is_preview: false,
    client_name: 'Alhambra',
    period: { from: '2026-09-09', until: '2026-10-08', days: 30 },
    comparison_period: { from: '2026-08-10', until: '2026-09-08' },
    source: 'sandbox',
    source_label: 'Sandbox',
    requires_label: true,
    currency: 'SAR',
    is_empty: false,
    totals: { spend: metricValue() },
    series: [{ date: '2026-09-09', source: 'sandbox', values: { spend: metricValue() } }],
    ...overrides,
  };
}

beforeEach(() => {
  state.entries = [entry()];
  state.performance = performance();
  state.reports = [];
});

describe('MyPortalPage', () => {
  it('builds its navigation from what the reader may open', async () => {
    await renderWithProviders(<MyPortalPage />);

    const nav = await screen.findByRole('navigation', { name: 'Your portal' });

    /*
     * Performance and the always-available thread, and nothing else. A menu item that answered 404 would tell a
     * client something about them exists and is being kept from them.
     */
    expect(within(nav).getByRole('button', { name: 'Performance' })).toBeInTheDocument();
    expect(within(nav).getByRole('button', { name: 'Requests' })).toBeInTheDocument();
    expect(within(nav).queryByRole('button', { name: 'Store revenue' })).not.toBeInTheDocument();
    expect(within(nav).queryByRole('button', { name: 'Tracking' })).not.toBeInTheDocument();
  });

  it('always offers the thread the client writes in, and says it is not a setting', async () => {
    await renderWithProviders(<MyPortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Requests' }));

    expect(await screen.findByTestId('requests')).toBeInTheDocument();
    expect(screen.getByText(/cannot switch this off/)).toBeInTheDocument();
  });

  it('names the baseline of every change figure and says the period ends yesterday', async () => {
    await renderWithProviders(<MyPortalPage />);

    expect(await screen.findByText(/2026-09-09 – 2026-10-08/)).toBeInTheDocument();
    /* A client who finds a percentage and no baseline will supply one of their own. */
    expect(screen.getByText(/Compared with 2026-08-10 – 2026-09-08/)).toBeInTheDocument();
    expect(
      screen.getByText(/ends yesterday, because today is still being counted/),
    ).toBeInTheDocument();
  });

  it('tells the client when a figure is not production data', async () => {
    await renderWithProviders(<MyPortalPage />);

    await screen.findByText(/2026-09-09 – 2026-10-08/);

    /*
     * Every provider in this repository is awaiting an authorization it cannot grant itself. A client reading
     * `Sandbox` has been told the truth; a client reading an unlabelled figure from a fixture has not.
     */
    expect(screen.getAllByText('Sandbox').length).toBeGreaterThan(0);
  });

  it('says a portal that was never set up was never set up', async () => {
    state.entries = [
      entry({
        state: 'not_configured',
        message: 'Your portal has not been set up yet. Your account manager can switch it on.',
        modules: [],
      }),
    ];

    await renderWithProviders(<MyPortalPage />);

    expect(await screen.findByText(/has not been set up yet/)).toBeInTheDocument();

    /*
     * And the thread is still there. A client with no portal can still ask for something and read what they were
     * told — that surface is not a setting, so there is nothing for the agency to have failed to switch on.
     */
    const nav = screen.getByRole('navigation', { name: 'Your portal' });
    expect(within(nav).getByRole('button', { name: 'Requests' })).toBeInTheDocument();
    expect(within(nav).queryByRole('button', { name: 'Performance' })).not.toBeInTheDocument();
  });

  it('says a switched-off portal is off, and never why', async () => {
    state.entries = [
      entry({
        state: 'suspended',
        message: 'Your portal is currently switched off. Please contact your account manager.',
        modules: [],
      }),
    ];

    await renderWithProviders(<MyPortalPage />);

    expect(await screen.findByText(/contact your account manager/)).toBeInTheDocument();
    /* The agency's own note is nowhere in the client's read. */
    expect(screen.queryByText(/overdue/i)).not.toBeInTheDocument();

    // The figures are closed; the conversation is not.
    const nav = screen.getByRole('navigation', { name: 'Your portal' });
    expect(within(nav).queryByRole('button', { name: 'Performance' })).not.toBeInTheDocument();
    expect(within(nav).getByRole('button', { name: 'Requests' })).toBeInTheDocument();
  });

  it('marks an agency preview as a preview', async () => {
    state.entries = [entry({ is_preview: true })];

    await renderWithProviders(<MyPortalPage />);

    /* An agency member who mistook this for their own view would conclude half the product had disappeared. */
    expect(await screen.findByText(/It is their page, not yours/)).toBeInTheDocument();
  });

  it('offers a chooser rather than guessing when a reader covers two clients', async () => {
    state.entries = [entry(), entry({ client_id: 'client-2', client_name: 'Beit Alwan' })];

    await renderWithProviders(<MyPortalPage />);

    const chooser = await screen.findByRole('group', { name: 'Account' });

    expect(within(chooser).getByRole('button', { name: 'Alhambra' })).toBeInTheDocument();
    expect(within(chooser).getByRole('button', { name: 'Beit Alwan' })).toBeInTheDocument();
  });

  it('says so when there is nothing at all', async () => {
    state.entries = [];

    await renderWithProviders(<MyPortalPage />);

    expect(await screen.findByText('Nothing here yet')).toBeInTheDocument();
  });
});
