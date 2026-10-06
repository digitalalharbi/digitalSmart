import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { BudgetCalculatorPage } from './BudgetCalculatorPage';
import type { ForecastResponse } from './types';

/**
 * What the acting role may do.
 *
 * Mutable so one test can take the permission away: a role without `budgets.view` sees
 * an explanation rather than a disabled form.
 */
const permissions = vi.hoisted(() => ({
  current: ['budgets.view'] as string[],
}));

vi.mock('@/features/auth', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/auth');

  return {
    ...actual,
    useSession: () => ({
      user: undefined,
      isLoading: false,
      isAuthenticated: true,
      can: (permission: string) => permissions.current.includes(permission),
    }),
  };
});

const runForecast = vi.hoisted(() => vi.fn());

vi.mock('./api', () => ({
  runForecast: (input: unknown) => runForecast(input),
}));

function response(overrides: Partial<ForecastResponse> = {}): ForecastResponse {
  return {
    benchmarks: {
      version: 'own-data/20260708-20261005/workspace/meta:21',
      source: 'This workspace’s own delivery. No industry averages are used.',
      window_start: '2026-07-08',
      window_end: '2026-10-05',
      client_id: null,
      project_id: null,
      providers: [
        {
          provider: 'meta',
          provider_label: 'Meta Ads',
          observed_days: 21,
          currency: 'SAR',
          confidence: 'high',
          confidence_label: 'High confidence',
          source: 'sandbox',
          spend_minor: 1_000_000,
          impressions: 400_000,
          clicks: 8_000,
          conversions: 240,
          rates: {
            cpm_minor: 2_500,
            cpm_low_minor: 2_200,
            cpm_high_minor: 2_900,
            cpc_minor: 125,
            cpc_low_minor: 110,
            cpc_high_minor: 150,
            ctr: 0.02,
            cvr: 0.03,
            cvr_low: 0.025,
            cvr_high: 0.035,
            cpa_minor: 4_166,
          },
          unavailable_reason: null,
        },
      ],
    },
    forecasts: [
      {
        provider: 'meta',
        provider_label: 'Meta Ads',
        confidence: 'high',
        confidence_label: 'High confidence',
        observed_days: 21,
        currency: 'SAR',
        source: 'estimated',
        rate_source: 'sandbox',
        projection: {
          impressions: 2_000_000,
          impressions_low: 1_724_137,
          impressions_high: 2_272_727,
          clicks: 40_000,
          clicks_low: 33_333,
          clicks_high: 45_454,
          conversions: 1_200,
          conversions_low: 833,
          conversions_high: 1_590,
          required_budget_minor: null,
          required_budget_low_minor: null,
          required_budget_high_minor: null,
          assumptions: ['clicks from the observed cost per click'],
        },
        gaps: [],
        unavailable_reason: null,
      },
    ],
    disclaimer: 'Estimates from this workspace’s own past delivery, not guarantees.',
    ...overrides,
  };
}

describe('BudgetCalculatorPage', () => {
  beforeEach(() => {
    permissions.current = ['budgets.view'];
    runForecast.mockReset();
    runForecast.mockResolvedValue(response());
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];
    await renderWithProviders(<BudgetCalculatorPage />);

    expect(await screen.findByText(/No access to budgets/i)).toBeInTheDocument();
    // Nothing is requested on behalf of somebody who may not see it.
    expect(runForecast).not.toHaveBeenCalled();
  });

  it('asks nothing until a figure is submitted', async () => {
    await renderWithProviders(<BudgetCalculatorPage />);

    // A calculator that fires on every keystroke would bill a rate-limited reporting
    // endpoint for each character typed.
    expect(runForecast).not.toHaveBeenCalled();
  });

  it('estimates results from a budget', async () => {
    await renderWithProviders(<BudgetCalculatorPage />);

    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    await waitFor(() => expect(runForecast).toHaveBeenCalledTimes(1));
    expect(runForecast.mock.calls[0]?.[0]).toMatchObject({ budget: '50000', window_days: 90 });

    expect(await screen.findByText('Meta Ads')).toBeInTheDocument();
    expect(screen.getByText('40,000')).toBeInTheDocument();
  });

  it('shows the band, not just the point estimate', async () => {
    await renderWithProviders(<BudgetCalculatorPage />);
    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    // The band is what makes the figure arguable.
    expect(await screen.findByText('33,333 – 45,454')).toBeInTheDocument();
  });

  it('shows the confidence, the sample and the disclaimer', async () => {
    await renderWithProviders(<BudgetCalculatorPage />);
    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    expect(await screen.findByText('High confidence')).toBeInTheDocument();
    expect(screen.getByText(/own-data\/20260708/)).toBeInTheDocument();
    expect(screen.getByText(/not guarantees/)).toBeInTheDocument();
    expect(screen.getByText(/21 days of delivery observed/)).toBeInTheDocument();
  });

  it('labels the projection as an estimate and its rates as sandbox', async () => {
    await renderWithProviders(<BudgetCalculatorPage />);
    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    /*
     * Both badges, deliberately: the projection is an estimate, and the rates behind it
     * came from fixtures. Losing either would let a sandbox-derived guess read as a
     * measurement.
     */
    expect(await screen.findByText(/Estimated/i)).toBeInTheDocument();
    expect(screen.getByText(/Sandbox/i)).toBeInTheDocument();
  });

  it('works a budget back from a target', async () => {
    runForecast.mockResolvedValue(
      response({
        forecasts: [
          {
            ...response().forecasts[0]!,
            projection: {
              ...response().forecasts[0]!.projection!,
              required_budget_minor: 1_041_600,
              required_budget_low_minor: 916_600,
              required_budget_high_minor: 1_250_000,
            },
          },
        ],
      }),
    );

    await renderWithProviders(<BudgetCalculatorPage />);

    await userEvent.click(screen.getByRole('button', { name: 'Target → budget' }));
    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    await waitFor(() => expect(runForecast).toHaveBeenCalledTimes(1));
    expect(runForecast.mock.calls[0]?.[0]).toMatchObject({
      target_metric: 'conversions',
      target_value: 250,
    });
    expect(await screen.findByText(/Budget needed/)).toBeInTheDocument();
  });

  it('shows a reason rather than a figure when a platform has too little data', async () => {
    runForecast.mockResolvedValue(
      response({
        forecasts: [
          {
            ...response().forecasts[0]!,
            confidence: 'none',
            confidence_label: 'Not enough data',
            observed_days: 2,
            projection: null,
            unavailable_reason: 'Only 2 days of delivery in this window.',
          },
        ],
      }),
    );

    await renderWithProviders(<BudgetCalculatorPage />);
    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    /*
     * The refusal is the feature. A number from two days of one campaign, rendered like
     * any other, is the kind of estimate that gets quoted back as a commitment.
     */
    expect(await screen.findByText(/Only 2 days of delivery/)).toBeInTheDocument();
    expect(screen.queryByText(/Clicks/)).not.toBeInTheDocument();
  });

  it('names what it could not work out', async () => {
    runForecast.mockResolvedValue(
      response({
        forecasts: [
          {
            ...response().forecasts[0]!,
            projection: {
              ...response().forecasts[0]!.projection!,
              conversions: null,
              conversions_low: null,
              conversions_high: null,
            },
            gaps: ['Conversions: no conversion rate has been observed for this scope.'],
          },
        ],
      }),
    );

    await renderWithProviders(<BudgetCalculatorPage />);
    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    // An em dash where the figure would be, and the reason spelled out below it.
    expect(await screen.findByText(/no conversion rate has been observed/)).toBeInTheDocument();
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('says plainly when there is nothing to estimate from', async () => {
    runForecast.mockResolvedValue(
      response({ forecasts: [], benchmarks: { ...response().benchmarks, providers: [] } }),
    );

    await renderWithProviders(<BudgetCalculatorPage />);
    await userEvent.click(screen.getByRole('button', { name: 'Estimate' }));

    expect(await screen.findByText(/Nothing to estimate from/)).toBeInTheDocument();
  });
});
