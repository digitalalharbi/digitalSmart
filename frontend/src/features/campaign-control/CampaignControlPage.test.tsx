import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { CampaignControlPage } from './CampaignControlPage';
import type { CampaignControlPage as Page, CampaignRow } from './types';

const query = vi.hoisted(() => vi.fn());

vi.mock('./useCampaignControl', () => ({ useCampaignControl: (f: unknown) => query(f) }));
vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));
vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));
vi.mock('@/features/search', () => ({
  useShortcutsQuery: () => ({ data: { pinned: [], recent: [] } }),
  usePinMutation: () => ({ mutate: vi.fn() }),
}));
vi.mock('@/features/integrations', () => ({
  useProvidersQuery: () => ({ data: [{ provider: 'meta', label: 'Meta' }] }),
  ProviderLogo: () => <span data-testid="logo" />,
}));

function row(o: Partial<CampaignRow> = {}): CampaignRow {
  return {
    id: 'c1',
    name: 'Ramadan Push',
    provider: 'meta',
    external_account: { id: 'a1', name: 'Meta KSA' },
    client_id: 'cl',
    client_name: 'Alhambra Retail',
    project_id: 'p',
    project_name: 'Ramadan',
    ownership: 'implied',
    ownership_label: 'From its account',
    status: 'active',
    status_label: 'Active',
    provider_status: 'ACTIVE',
    health: 'critical',
    health_label: 'Critical',
    health_reason: 'Spending, and nothing has been recorded against it in this period.',
    needs_attention: true,
    objective: 'leads',
    objective_label: 'Leads',
    currency: 'SAR',
    budget_minor: 100_000,
    spend_minor: 500_000,
    result: 0,
    result_label: 'leads',
    cost_per_result_minor: null,
    revenue_minor: null,
    roas: null,
    budget_utilisation: 0.5,
    trend: [1, 2, 3, null, 5],
    reported_days: 28,
    last_synced_at: '2026-06-15T09:00:00Z',
    tags: [],
    ...o,
  };
}

function page(
  rows: CampaignRow[],
  matched = rows.length,
): { data: Page; isPending: boolean; isError: boolean; isFetching: boolean } {
  return {
    data: {
      data: rows,
      meta: {
        health_counts: {
          healthy: 3,
          watch: 2,
          needs_attention: 1,
          critical: 4,
          inactive: 5,
          insufficient_data: 0,
        },
        total: matched,
        matched,
        active_count: 10,
        assessed: matched,
        is_truncated: false,
        window: { from: '2026-05-18', to: '2026-06-14' },
        limit: 24,
        offset: 0,
        health: [
          { health: 'critical', label: 'Critical', needs_attention: true, rank: 0 },
          { health: 'healthy', label: 'Healthy', needs_attention: false, rank: 4 },
        ],
        statuses: [{ status: 'active', label: 'Active' }],
        objectives: [{ objective: 'leads', label: 'Leads', result_label: 'leads' }],
      },
    },
    isPending: false,
    isError: false,
    isFetching: false,
  };
}

describe('CampaignControlPage', () => {
  beforeEach(() => {
    query.mockReset();
    query.mockReturnValue(page([row()]));
  });

  it('shows the platform’s status and the product’s verdict together', async () => {
    await renderWithProviders(<CampaignControlPage />);
    const card = screen.getByRole('article', { name: 'Ramadan Push' });

    expect(within(card).getByText('Critical')).toBeInTheDocument();
    expect(within(card).getByText('Active')).toBeInTheDocument();
    expect(within(card).getByText(/nothing has been recorded/)).toBeInTheDocument();
  });

  /* The server withholds ROAS for an objective that does not measure revenue; the page must not invent a slot. */
  it('shows no ROAS where the server sent none', async () => {
    await renderWithProviders(<CampaignControlPage />);
    const card = screen.getByRole('article', { name: 'Ramadan Push' });

    expect(within(card).queryByText('ROAS')).not.toBeInTheDocument();
  });

  it('prints a dash for a figure nobody reported, never a zero', async () => {
    query.mockReturnValue(
      page([row({ spend_minor: null, cost_per_result_minor: null, result: null })]),
    );
    await renderWithProviders(<CampaignControlPage />);
    const card = screen.getByRole('article', { name: 'Ramadan Push' });

    expect(within(card).getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('narrows to a health state from the distribution', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<CampaignControlPage />);

    await user.click(screen.getByRole('button', { name: /Critical\s*4/ }));

    expect(query).toHaveBeenLastCalledWith(expect.objectContaining({ health: 'critical' }));
  });

  it('pages through the matched set rather than the loaded one', async () => {
    query.mockReturnValue(page([row()], 100));
    await renderWithProviders(<CampaignControlPage />);

    expect(screen.getByText('1–24 of 100')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
  });

  it('says how much of the agency the verdicts cover when it is capped', async () => {
    const capped = page([row()], 2500);
    capped.data.meta.is_truncated = true;
    capped.data.meta.assessed = 2000;
    query.mockReturnValue(capped);
    await renderWithProviders(<CampaignControlPage />);

    expect(screen.getByText(/2000 highest-spending of 2500/)).toBeInTheDocument();
  });

  /* The agency's own vocabulary reaches the card: the campaign's tags plus those inherited from its project. */
  it('shows the internal tags a campaign carries', async () => {
    query.mockReturnValue(
      page([
        row({
          tags: [
            { id: 't1', name: 'Ramadan' },
            { id: 't2', name: 'High spend' },
          ],
        }),
      ]),
    );
    await renderWithProviders(<CampaignControlPage />);
    const card = screen.getByRole('article', { name: 'Ramadan Push' });

    expect(
      within(within(card).getByRole('list', { name: 'Tags' })).getAllByRole('listitem'),
    ).toHaveLength(2);
  });

  it('offers to pin a campaign, by name', async () => {
    await renderWithProviders(<CampaignControlPage />);

    expect(screen.getByRole('button', { name: 'Pin Ramadan Push' })).toBeInTheDocument();
  });
});
