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
