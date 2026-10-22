import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { AdGroupsPage } from './AdGroupsPage';
import type { AdGroupPage, AdGroupRow } from './types';

const query = vi.hoisted(() => vi.fn());

vi.mock('./useAdGroups', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  useAdGroups: query,
}));
vi.mock('@/features/clients', () => ({ useClientsQuery: () => ({ data: { data: [] } }) }));
vi.mock('@/features/surface-views', () => ({ SavedViewsMenu: () => null }));
vi.mock('@/features/integrations', () => ({
  useProvidersQuery: () => ({ data: [{ provider: 'meta', label: 'Meta' }] }),
  ProviderLogo: () => <span data-testid="logo" />,
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
      reason: 'Spending, nothing recorded.',
      reason_code: 'X',
      reason_params: {},
    },
    ...overrides,
  };
}

function page(rows: AdGroupRow[], matched = rows.length) {
  const data: AdGroupPage = {
    data: rows,
    meta: {
      matched,
      is_truncated: false,
      days: 28,
      limit: 24,
      offset: 0,
      spend_by_currency: [{ currency: 'SAR', spend_minor: 250_000 }],
      statuses: [{ status: 'active', label: 'Active' }],
    },
  };

  return { data, isPending: false, isError: false, isFetching: false };
}

describe('AdGroupsPage', () => {
  beforeEach(() => {
    query.mockReset();
    query.mockReturnValue(page([row()]));
  });

  it('lists each ad group with its parent campaign, client and project, and links to both', async () => {
    await renderWithProviders(<AdGroupsPage />);
    const table = screen.getByRole('table', { name: 'Ad Groups' });

    expect(within(table).getByRole('link', { name: 'Lookalike 1%' })).toHaveAttribute(
      'href',
      '/ad-groups/g1',
    );
    expect(within(table).getByRole('link', { name: 'Ramadan Push' })).toHaveAttribute(
      'href',
      '/campaigns/k1',
    );
    expect(within(table).getByText('Alhambra Retail')).toBeInTheDocument();
    expect(within(table).getByText('Ramadan')).toBeInTheDocument();
    expect(within(table).getByText('2.00%')).toBeInTheDocument();
  });

  it('labels the health column as the campaign’s, because an ad group has none of its own', async () => {
    await renderWithProviders(<AdGroupsPage />);

    expect(screen.getByRole('columnheader', { name: 'Campaign health' })).toBeInTheDocument();
    expect(screen.getByText('Critical')).toBeInTheDocument();
  });

  it('prints a dash for a figure nobody reported, never a zero', async () => {
    query.mockReturnValue(
      page([
        row({
          spend_minor: null,
          cost_per_result_minor: null,
          result: null,
          ctr: null,
          budget_minor: null,
          budget_utilisation: null,
        }),
      ]),
    );
    await renderWithProviders(<AdGroupsPage />);
    const table = screen.getByRole('table', { name: 'Ad Groups' });

    expect(within(table).getAllByText('—').length).toBeGreaterThanOrEqual(5);
    expect(within(table).queryByText('0')).not.toBeInTheDocument();
  });

  it('shows spend per currency over everything matched, not the page', async () => {
    query.mockReturnValue(page([row()], 100));
    await renderWithProviders(<AdGroupsPage />);
    const summary = screen.getByRole('region', { name: 'Ad group summary' });

    expect(within(summary).getByText('100')).toBeInTheDocument();
    expect(within(summary).getByText(/Spend \(SAR\)/)).toBeInTheDocument();
  });

  it('sorts from the column head, and does not offer to sort what the server cannot order', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<AdGroupsPage />);

    await user.click(screen.getByRole('button', { name: /^Cost per result/ }));
    expect(query).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'cost' }));
    expect(screen.queryByRole('button', { name: /^Budget/ })).not.toBeInTheDocument();
  });

  it('asks for what was chosen, from the address', async () => {
    await renderWithProviders(<AdGroupsPage />, {
      route: '/ad-groups?campaign=k1~Ramadan%20Push&zero=1&status=active',
    });

    expect(query).toHaveBeenLastCalledWith(
      expect.objectContaining({
        campaign: { id: 'k1', label: 'Ramadan Push' },
        zeroResults: true,
        status: 'active',
      }),
    );
    expect(screen.getByText(/In campaign/)).toBeInTheDocument();
  });

  it('says plainly when nothing matches, and offers to start over', async () => {
    query.mockReturnValue(page([]));
    await renderWithProviders(<AdGroupsPage />, { route: '/ad-groups?q=zzz' });

    expect(screen.getByText('No ad groups match these filters')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Clear all filters' })).toBeInTheDocument();
  });
});
