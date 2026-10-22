import { screen, within } from '@testing-library/react';
import { Route, Routes } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { AdGroupDetailPage } from './AdGroupDetailPage';
import type { AdGroupRow } from './types';

type State = { row: AdGroupRow | null; audience: string | null };

const state = vi.hoisted((): State => ({ row: null, audience: null }));

vi.mock('./useAdGroups', () => ({
  useAdGroup: () => ({
    isPending: false,
    data: { data: state.row === null ? [] : [state.row], meta: {} },
  }),
}));
vi.mock('@/features/analytics', () => ({
  HeroTrend: () => <div data-testid="trend" />,
  analyticsAsReport: () => ({ sections: [] }),
  useAnalytics: () => ({ data: { is_empty: false } }),
  useMetricCatalogue: () => ({ definitions: new Map() }),
}));
vi.mock('@/features/reports', () => ({ ReportHighlights: () => <div data-testid="funnel" /> }));
vi.mock('@/features/integrations', () => ({ ProviderLogo: () => <span /> }));
vi.mock('@/features/campaign-control', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  useCampaignHierarchy: () => ({
    isPending: false,
    data: {
      groups: [
        {
          id: 'g1',
          name: 'Lookalike 1%',
          audience: state.audience,
          ads: [
            {
              id: 'a1',
              name: 'Video — Ramadan',
              status: 'active',
              creative: { id: 'cr', name: 'v', format: 'video', title: null, thumbnail_url: null },
              spend_minor: 90_000,
              ctr_now: null,
              ctr_change: null,
              frequency: null,
            },
          ],
        },
      ],
    },
  }),
}));

function row(overrides: Partial<AdGroupRow> = {}): AdGroupRow {
  return {
    id: 'g1',
    name: 'Lookalike 1%',
    provider: 'meta',
    campaign: { id: 'k1', name: 'Ramadan Push' },
    client_id: 'cl',
    client_name: 'Alhambra Retail',
    project_id: 'p',
    project_name: 'Ramadan',
    status: 'active',
    status_label: 'Active',
    objective: 'leads',
    objective_label: 'Leads',
    currency: 'SAR',
    budget_minor: 50_000,
    budget_utilisation: 0.5,
    spend_minor: 250_000,
    result: 12,
    result_label: 'leads',
    cost_per_result_minor: 20_833,
    revenue_minor: null,
    roas: null,
    impressions: 100_000,
    clicks: 2_000,
    ctr: 0.02,
    starts_on: null,
    ends_on: null,
    reported_days: 28,
    campaign_health: {
      health: 'critical',
      label: 'Critical',
      reason: 'x',
      reason_code: 'X',
      reason_params: {},
    },
    ...overrides,
  };
}

function page() {
  return renderWithProviders(
    <Routes>
      <Route path="/ad-groups/:adGroupId" element={<AdGroupDetailPage />} />
    </Routes>,
    { route: '/ad-groups/g1' },
  );
}

describe('AdGroupDetailPage', () => {
  beforeEach(() => {
    state.row = row();
    state.audience = null;
  });

  it('leads with the trail back to its campaign, then the ad group', async () => {
    await page();
    const trail = screen.getByRole('navigation', { name: 'Where you are' });

    expect(within(trail).getByRole('link', { name: 'Campaigns' })).toHaveAttribute(
      'href',
      '/campaigns',
    );
    expect(within(trail).getByRole('link', { name: 'Ramadan Push' })).toHaveAttribute(
      'href',
      '/campaigns/k1',
    );
    expect(within(trail).getByText('Lookalike 1%')).toHaveAttribute('aria-current', 'page');
  });

  it('shows the figures the objective measures, and omits revenue, ROAS and CTR it does not have', async () => {
    state.row = row({ ctr: null });
    await page();
    const figures = screen.getByRole('region', { name: 'Key figures' });

    expect(within(figures).queryByText('ROAS')).not.toBeInTheDocument();
    expect(within(figures).queryByText('Revenue')).not.toBeInTheDocument();
    expect(within(figures).queryByText('CTR')).not.toBeInTheDocument();
    expect(within(figures).getByText('Spend')).toBeInTheDocument();
  });

  it('names the verdict as the campaign’s, not the ad group’s', async () => {
    await page();

    expect(screen.getByText('Critical')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Campaign health' })).toHaveAttribute(
      'href',
      '/campaigns/k1',
    );
  });

  it('lists the ads inside this ad group', async () => {
    await page();
    const ads = screen.getByRole('list', { name: 'Ads in this ad group' });

    expect(within(ads).getByText('Video — Ramadan')).toBeInTheDocument();
  });

  it('shows an audience only where the platform sync stored one', async () => {
    await page();
    expect(screen.queryByText('Audience')).not.toBeInTheDocument();
  });

  it('shows the audience when one was stored', async () => {
    state.audience = 'Women 25–34, Riyadh';
    await page();

    expect(screen.getByText('Women 25–34, Riyadh')).toBeInTheDocument();
  });

  it('says so when the ad group is not one the reader may see', async () => {
    state.row = null;
    await page();

    expect(
      screen.getByText('This ad group does not exist or is not one you may see.'),
    ).toBeInTheDocument();
  });
});
