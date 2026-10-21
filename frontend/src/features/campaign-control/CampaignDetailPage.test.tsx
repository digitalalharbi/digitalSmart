import { Route, Routes } from 'react-router';
import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { CampaignDetailPage } from './CampaignDetailPage';

const state = vi.hoisted(() => ({ revenue: null as number | null, roas: null as number | null }));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: {
      get: (url: string) => {
        if (url.endsWith('/readiness')) {
          return Promise.resolve({
            data: {
              data: {
                overall: { state: 'needs_attention', label: 'Needs attention' },
                checks: [
                  {
                    key: 'placed',
                    state: 'ready',
                    label: 'Ready',
                    reason: 'It has been placed in a project.',
                  },
                ],
              },
            },
          });
        }

        if (url.endsWith('/hierarchy')) {
          return Promise.resolve({
            data: { data: { groups: [], creatives: [], writes: { ad_group: {}, ad: {} } } },
          });
        }

        if (url === '/auth/me') {
          return Promise.resolve({ data: { data: { permissions: [] } } });
        }

        if (url.startsWith('/analytics/catalogue')) {
          return Promise.resolve({
            data: {
              data: {
                metrics: ['spend', 'clicks', 'ctr'].map((value) => ({
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
                providers: [],
                sources: [],
              },
            },
          });
        }

        if (url.startsWith('/analytics')) {
          const figure = (metric: string, value: number) => ({
            metric,
            label: metric.toUpperCase(),
            value,
            format: 'count',
            precision: 0,
            is_minor_units: false,
            currency: null,
            source: 'synced',
            source_label: 'Synced',
            requires_label: false,
            unavailable_reason: null,
            comparison_value: null,
            change_ratio: null,
            change_is_good: null,
            lower_is_better: false,
          });
          const set = (value: number) => ({
            source: 'synced',
            currency: null,
            row_count: 3,
            values: {
              spend: figure('spend', value),
              impressions: figure('impressions', 10_000),
              clicks: figure('clicks', 400),
              conversions: figure('conversions', 12),
            },
          });

          return Promise.resolve({
            data: {
              data: {
                range: { start: '2026-09-17', end: '2026-10-16', days: 30 },
                compare_to: null,
                metrics: ['spend', 'clicks', 'ctr', 'conversions', 'impressions'],
                source: 'synced',
                source_label: 'Synced',
                requires_label: false,
                is_empty: false,
                summary: set(500),
                series: [
                  { date: '2026-10-15', ...set(250) },
                  { date: '2026-10-16', ...set(250) },
                ],
                breakdown: [],
                dimension: null,
              },
            },
          });
        }

        if (url.startsWith('/budgets/control')) {
          return Promise.resolve({ data: { data: [] } });
        }

        return Promise.resolve({
          data: {
            data: [
              {
                id: 'c1',
                name: 'Ramadan Push',
                provider: 'meta',
                external_account: { id: 'a1', name: 'Meta KSA' },
                client_name: 'Alhambra',
                project_name: 'Ramadan',
                health: 'critical',
                health_reason: 'Spending with nothing recorded.',
                provider_status: 'ACTIVE',
                objective_label: 'Leads',
                currency: 'SAR',
                budget_minor: 100_000,
                spend_minor: 500_000,
                result: 12,
                result_label: 'leads',
                cost_per_result_minor: 41_666,
                revenue_minor: state.revenue,
                roas: state.roas,
                budget_utilisation: 0.5,
                last_synced_at: '2026-10-15T09:00:00Z',
              },
            ],
            meta: { spend_trend: [{ currency: 'SAR', points: [1, 2, 3, 4] }] },
          },
        });
      },
    },
  };
});

function page() {
  return renderWithProviders(
    <Routes>
      <Route path="/campaigns/:campaignId" element={<CampaignDetailPage />} />
    </Routes>,
    { route: '/campaigns/c1' },
  );
}

describe('CampaignDetailPage — analytics', () => {
  it("reads this campaign's own figures into a hero chart and a funnel, from the shared analytics layer", async () => {
    await page();

    expect(await screen.findByRole('group', { name: /Ramadan Push/ })).toBeInTheDocument();
    // The funnel is the report system's reader over the same figures: impressions, clicks, then the outcome.
    expect(await screen.findByText('From seen to converted')).toBeInTheDocument();
  });
});

describe('CampaignDetailPage', () => {
  it('leads with the verdict and the figures the objective measures', async () => {
    await page();

    expect(await screen.findByText('Spending with nothing recorded.')).toBeInTheDocument();
    expect(screen.getByText('Critical')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Key figures' })).toBeInTheDocument();
  });

  it('shows no revenue or ROAS for a campaign that does not measure them', async () => {
    state.revenue = null;
    state.roas = null;
    await page();

    await screen.findByText('Spending with nothing recorded.');

    expect(screen.queryByText('ROAS')).not.toBeInTheDocument();
    expect(screen.queryByText('Revenue')).not.toBeInTheDocument();
  });

  it('keeps platform detail under Advanced', async () => {
    await page();
    await screen.findByText('Spending with nothing recorded.');

    expect(screen.getByText('Advanced').closest('details')).toContainElement(
      screen.getByText('ACTIVE'),
    );
  });
});
