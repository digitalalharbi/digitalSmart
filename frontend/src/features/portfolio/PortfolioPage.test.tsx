import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { PortfolioPage } from './PortfolioPage';
import { ClientPortfolioPage } from './ClientPortfolioPage';
import type { Portfolio } from './types';

const query = vi.hoisted(() => vi.fn());

vi.mock('./usePortfolio', () => ({ usePortfolio: () => query() }));
vi.mock('@/features/attention', () => ({ AttentionPanel: () => <div data-testid="attention" /> }));
vi.mock('react-router', async () => ({
  ...(await vi.importActual<Record<string, unknown>>('react-router')),
  useParams: () => ({ clientId: 'c1' }),
}));

const health = {
  healthy: 3,
  watch: 1,
  needs_attention: 2,
  critical: 1,
  inactive: 0,
  insufficient_data: 0,
};

function portfolio(overrides: Partial<Portfolio> = {}): Portfolio {
  const figures = {
    spend: [
      { currency: 'SAR', minor: 1_500_000 },
      { currency: 'USD', minor: 40_000 },
    ],
    platform_revenue: [{ currency: 'SAR', minor: 6_000_000 }],
    roas: [{ currency: 'SAR', value: 4 }],
    active_campaigns: 5,
    campaigns: 7,
    campaign_health: health,
    needs_attention: 3,
  };

  return {
    window: { from: '2026-05-18', to: '2026-06-14' },
    kpis: {
      ...figures,
      leads: null,
      qualified_leads: null,
      sales: 12,
      store_revenue: [],
      active_clients: 4,
      active_projects: 9,
    },
    health,
    clients: [
      {
        id: 'c1',
        name: 'Alhambra Retail',
        status: 'active',
        ...figures,
        leads: null,
        qualified_leads: null,
        sales: 12,
        budget_risks: 2,
        tracking_attention: 1,
        projects: 2,
      },
    ],
    projects: [
      {
        id: 'p1',
        client_id: 'c1',
        name: 'Ramadan',
        status: 'onboarding',
        ...figures,
        open_work: 6,
      },
    ],
    platform_mix: [{ provider: 'meta', spend: [{ currency: 'SAR', minor: 1_000_000 }] }],
    budget_health: { approved: 5, at_risk: 2 },
    tracking_health: null,
    assessed: 7,
    total_campaigns: 7,
    is_truncated: false,
    ...overrides,
  };
}

const ok = (data: Portfolio) => ({ data, isPending: false, isError: false });

describe('PortfolioPage', () => {
  beforeEach(() => {
    query.mockReset();
    query.mockReturnValue(ok(portfolio()));
  });

  /* Two currencies are two lines. A sum across them would be a number with no unit. */
  it('lists spend per currency rather than adding currencies together', async () => {
    await renderWithProviders(<PortfolioPage />);
    const kpis = screen.getByRole('region', { name: 'Key figures' });

    expect(within(kpis).getAllByText(/SAR|ر\.س/).length).toBeGreaterThan(0);
    expect(within(kpis).getAllByText(/\$/).length).toBeGreaterThan(0);
  });

  it('ranks clients by spend in one currency at a time, and lets the other be chosen', async () => {
    await renderWithProviders(<PortfolioPage />);

    const [first] = screen.getAllByRole('link', { name: /Alhambra Retail/ });
    expect(first).toHaveAttribute('href', '/clients/c1');
    expect(screen.getByRole('button', { name: 'USD' })).toBeInTheDocument();
  });

  it('shows each project as a tile that says its worst state and opens it', async () => {
    await renderWithProviders(<PortfolioPage />);

    const tile = screen.getByRole('link', { name: /Ramadan/ });

    expect(tile).toHaveAttribute('href', '/projects/p1');
    expect(tile).toHaveTextContent('Critical');
  });

  /* Not shown to the role is "—" with a reason, never a zero a reader would take as a fact. */
  it('draws a section the role cannot see as a dash, not a zero', async () => {
    await renderWithProviders(<PortfolioPage />);

    expect(screen.queryByText('Tracking needing attention')).not.toBeInTheDocument();
    expect(
      within(screen.getByRole('region', { name: 'Key figures' })).getAllByText('—').length,
    ).toBeGreaterThanOrEqual(2);
  });

  it('links each client to its own page', async () => {
    await renderWithProviders(<PortfolioPage />);

    expect(screen.getByRole('link', { name: 'Alhambra Retail' })).toHaveAttribute(
      'href',
      '/clients/c1',
    );
  });

  it('says how much of the agency the verdicts cover when it is capped', async () => {
    query.mockReturnValue(
      ok(portfolio({ is_truncated: true, assessed: 2000, total_campaigns: 2400 })),
    );
    await renderWithProviders(<PortfolioPage />);

    expect(screen.getByRole('alert')).toHaveTextContent('2000');
  });
});

describe('ClientPortfolioPage', () => {
  beforeEach(() => {
    query.mockReset();
    query.mockReturnValue(ok(portfolio()));
  });

  it('shows each project of the client as a card with its status and open work', async () => {
    await renderWithProviders(<ClientPortfolioPage />);
    const card = screen.getByRole('article', { name: 'Ramadan' });

    expect(within(card).getByText('Onboarding')).toBeInTheDocument();
    expect(within(card).getByText('6')).toBeInTheDocument();
  });

  it('says so when the client does not exist or is not one of the reader’s', async () => {
    query.mockReturnValue(ok(portfolio({ clients: [] })));
    await renderWithProviders(<ClientPortfolioPage />);

    expect(screen.getByText('No such client')).toBeInTheDocument();
  });
});
