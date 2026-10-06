import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { MediaPlannerPage } from './MediaPlannerPage';
import type { BudgetPayload, PacingPayload } from './types';

const permissions = vi.hoisted(() => ({
  current: ['budgets.view', 'budgets.manage', 'budgets.approve'] as string[],
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

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Alhambra Retail' }] },
  }),
}));

vi.mock('@/features/integrations', () => ({
  useProvidersQuery: () => ({
    isPending: false,
    data: [
      {
        provider: 'meta',
        label: 'Meta Ads',
        capabilities: [{ slug: 'metrics_sync', label: 'Metrics', is_write: false }],
      },
      {
        provider: 'openai',
        label: 'ChatGPT Ads',
        // No metrics sync, so it is never offered as an allocation target.
        capabilities: [],
      },
    ],
  }),
}));

const fetchBudgets = vi.hoisted(() => vi.fn());
const fetchBudget = vi.hoisted(() => vi.fn());
const fetchPacing = vi.hoisted(() => vi.fn());
const fetchAlerts = vi.hoisted(() => vi.fn());
const createBudget = vi.hoisted(() => vi.fn());
const updateBudget = vi.hoisted(() => vi.fn());
const approveBudget = vi.hoisted(() => vi.fn());
const archiveBudget = vi.hoisted(() => vi.fn());
const deleteBudget = vi.hoisted(() => vi.fn());
const saveAllocations = vi.hoisted(() => vi.fn());

vi.mock('./api', () => ({
  fetchBudgets: (params: unknown) => fetchBudgets(params),
  fetchBudget: (id: string) => fetchBudget(id),
  fetchPacing: (id: string) => fetchPacing(id),
  fetchAlerts: () => fetchAlerts(),
  createBudget: (draft: unknown) => createBudget(draft),
  updateBudget: (id: string, draft: unknown) => updateBudget(id, draft),
  approveBudget: (id: string) => approveBudget(id),
  archiveBudget: (id: string) => archiveBudget(id),
  deleteBudget: (id: string) => deleteBudget(id),
  saveAllocations: (id: string, allocations: unknown) => saveAllocations(id, allocations),
}));

function budget(overrides: Partial<BudgetPayload> = {}): BudgetPayload {
  return {
    id: 'budget-1',
    client_id: 'client-1',
    client_name: 'Alhambra Retail',
    project_id: null,
    project_name: null,
    name: 'Q4 retail',
    period_start: '2026-10-01',
    period_end: '2026-12-31',
    amount_minor: 18_000_000,
    currency: 'SAR',
    status: 'draft',
    status_label: 'Draft',
    amount_is_editable: true,
    allocations_are_editable: true,
    is_paced: false,
    approved_by_name: null,
    approved_at: null,
    allocated_minor: 0,
    unallocated_minor: 18_000_000,
    allocations: [],
    notes: null,
    updated_at: null,
    ...overrides,
  };
}

function pacing(overrides: Partial<PacingPayload> = {}): PacingPayload {
  return {
    budget: budget({ status: 'approved', status_label: 'Approved', is_paced: true }),
    spend: {
      minor_units: 6_000_000,
      currency: 'SAR',
      source: 'sandbox',
      source_label: 'Sandbox',
      unavailable_reason: null,
      rows_reported: 40,
    },
    pacing: {
      status: 'on_track',
      status_label: 'On track',
      needs_attention: false,
      budget_minor: 18_000_000,
      spent_minor: 6_000_000,
      expected_minor: 6_000_000,
      variance_minor: 0,
      variance_percent: 0,
      remaining_minor: 12_000_000,
      projected_minor: 18_000_000,
      daily_allowance_minor: 200_000,
      total_days: 92,
      elapsed_days: 31,
      remaining_days: 61,
      utilisation: 0.33,
      period_elapsed: 0.34,
      risks_overspend: false,
      risks_under_delivery: false,
      currency: 'SAR',
      tolerance: 0.1,
    },
    unavailable_reason: null,
    allocations: [],
    daily: [{ date: '2026-10-01', spendMinor: 200_000 }],
    ...overrides,
  };
}

describe('MediaPlannerPage', () => {
  beforeEach(() => {
    permissions.current = ['budgets.view', 'budgets.manage', 'budgets.approve'];
    for (const mock of [
      fetchBudgets,
      fetchBudget,
      fetchPacing,
      fetchAlerts,
      createBudget,
      updateBudget,
      approveBudget,
      archiveBudget,
      deleteBudget,
      saveAllocations,
    ]) {
      mock.mockReset();
    }

    fetchBudgets.mockResolvedValue([budget()]);
    fetchBudget.mockResolvedValue(budget());
    fetchPacing.mockResolvedValue(
      pacing({ budget: budget(), pacing: null, unavailable_reason: null }),
    );
    fetchAlerts.mockResolvedValue([]);
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];
    await renderWithProviders(<MediaPlannerPage />);

    expect(await screen.findByText(/No access to budgets/i)).toBeInTheDocument();
    expect(fetchBudgets).not.toHaveBeenCalled();
  });

  it('lands on the first budget without an effect choosing it', async () => {
    await renderWithProviders(<MediaPlannerPage />);

    // Derived from the list, not synced into state: an effect that re-selected while a
    // draft was open is exactly how the dashboard builder once overwrote a dashboard.
    expect(await screen.findByRole('heading', { level: 2, name: 'Q4 retail' })).toBeInTheDocument();
    await waitFor(() => expect(fetchBudget).toHaveBeenCalledWith('budget-1'));
  });

  it('offers approval on a draft', async () => {
    approveBudget.mockResolvedValue(budget({ status: 'approved', status_label: 'Approved' }));
    await renderWithProviders(<MediaPlannerPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));

    await waitFor(() => expect(approveBudget).toHaveBeenCalledWith('budget-1'));
  });

  it('hides approval from a role that may plan but not commit', async () => {
    permissions.current = ['budgets.view', 'budgets.manage'];
    await renderWithProviders(<MediaPlannerPage />);

    await screen.findByRole('heading', { level: 2, name: 'Q4 retail' });

    /*
     * A courtesy, not the boundary — the server refuses it either way. But offering a
     * control that always fails teaches people to distrust the interface.
     */
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Edit$/ })).toBeInTheDocument();
  });

  it('offers no authoring controls to a reader', async () => {
    permissions.current = ['budgets.view'];
    await renderWithProviders(<MediaPlannerPage />);

    await screen.findByRole('heading', { level: 2, name: 'Q4 retail' });

    expect(screen.queryByRole('button', { name: 'New budget' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Edit allocation/ })).not.toBeInTheDocument();
  });

  it('says why an approved budget cannot be re-costed', async () => {
    const approved = budget({
      status: 'approved',
      status_label: 'Approved',
      amount_is_editable: false,
      is_paced: true,
      approved_by_name: 'Demo Owner',
      approved_at: '2026-10-01T09:00:00+00:00',
    });
    fetchBudgets.mockResolvedValue([approved]);
    fetchBudget.mockResolvedValue(approved);
    fetchPacing.mockResolvedValue(pacing({ budget: approved }));

    await renderWithProviders(<MediaPlannerPage />);

    expect(
      await screen.findByText(/archive this budget and approve a new one/),
    ).toBeInTheDocument();
    expect(screen.getByText(/Approved by Demo Owner/)).toBeInTheDocument();
  });

  it('shows pacing against the spend, labelled with its source', async () => {
    const approved = budget({ status: 'approved', status_label: 'Approved', is_paced: true });
    fetchBudgets.mockResolvedValue([approved]);
    fetchBudget.mockResolvedValue(approved);
    fetchPacing.mockResolvedValue(pacing({ budget: approved }));

    await renderWithProviders(<MediaPlannerPage />);

    expect(await screen.findByText('On track')).toBeInTheDocument();
    // Sandbox spend must be visibly sandbox spend, all the way to the pacing panel.
    expect(screen.getByText(/Sandbox/i)).toBeInTheDocument();
  });

  it('shows the reason instead of a zero when spend cannot be totalled', async () => {
    const approved = budget({ status: 'approved', status_label: 'Approved', is_paced: true });
    fetchBudgets.mockResolvedValue([approved]);
    fetchBudget.mockResolvedValue(approved);
    fetchPacing.mockResolvedValue(
      pacing({
        budget: approved,
        spend: {
          minor_units: null,
          currency: null,
          source: 'sandbox',
          source_label: 'Sandbox',
          unavailable_reason: 'The accounts in this budget report in SAR and USD.',
          rows_reported: 12,
        },
        pacing: null,
        unavailable_reason: 'The accounts in this budget report in SAR and USD.',
      }),
    );

    await renderWithProviders(<MediaPlannerPage />);

    /*
     * The one failure this product treats as unrecoverable is an understated spend shown
     * as a measurement. So the panel says what it cannot tell, and shows no variance.
     */
    expect(await screen.findByText(/report in SAR and USD/)).toBeInTheDocument();
    expect(screen.getByText('Not paced')).toBeInTheDocument();
  });

  it('lists an alerting budget and jumps to it', async () => {
    const approved = budget({
      id: 'budget-2',
      name: 'Overspending one',
      status: 'approved',
      status_label: 'Approved',
      is_paced: true,
    });
    fetchBudgets.mockResolvedValue([budget(), approved]);
    fetchAlerts.mockResolvedValue([
      pacing({
        budget: approved,
        pacing: {
          ...pacing().pacing!,
          status: 'overspending',
          status_label: 'Overspending',
          needs_attention: true,
          risks_overspend: true,
        },
      }),
    ]);

    await renderWithProviders(<MediaPlannerPage />);

    expect(await screen.findByText('Needs looking at')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Overspending one' }));

    await waitFor(() => expect(fetchBudget).toHaveBeenCalledWith('budget-2'));
  });

  it('offers only platforms whose API can report spend', async () => {
    await renderWithProviders(<MediaPlannerPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Edit allocation' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add a platform' }));

    /*
     * ChatGPT Ads declares no metrics capability, so an allocation to it could never be
     * compared with spend — a number in a spreadsheet rather than a budget.
     */
    expect(screen.getByRole('button', { name: 'Meta Ads' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'ChatGPT Ads' })).not.toBeInTheDocument();
  });

  it('reports the unallocated remainder rather than spreading it', async () => {
    await renderWithProviders(<MediaPlannerPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Edit allocation' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add a platform' }));

    const amount = screen.getByLabelText(/Amount/);
    await userEvent.clear(amount);
    await userEvent.type(amount, '100000');

    /*
     * 80,000 left of 180,000. Auto-spreading it would be a media decision made on
     * somebody's behalf and called a convenience.
     */
    expect(await screen.findByText(/SAR 80,000\.00 not allocated yet/)).toBeInTheDocument();
  });

  it('warns when the parts come to more than the whole', async () => {
    await renderWithProviders(<MediaPlannerPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Edit allocation' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add a platform' }));

    const amount = screen.getByLabelText(/Amount/);
    await userEvent.clear(amount);
    await userEvent.type(amount, '200000');

    // Fast feedback here; the refusal is still the server's.
    expect(await screen.findByText(/SAR 20,000\.00 over the budget/)).toBeInTheDocument();
  });

  it('sends the allocation tree as decimals the API accepts', async () => {
    saveAllocations.mockResolvedValue(budget());
    await renderWithProviders(<MediaPlannerPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Edit allocation' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add a platform' }));

    const amount = screen.getByLabelText(/Amount/);
    await userEvent.clear(amount);
    await userEvent.type(amount, '100000');

    await userEvent.click(screen.getByRole('button', { name: 'Save allocation' }));

    await waitFor(() => expect(saveAllocations).toHaveBeenCalledTimes(1));
    expect(saveAllocations.mock.calls[0]?.[1]).toEqual([
      expect.objectContaining({ level: 'platform', provider: 'meta', amount: '100000' }),
    ]);
  });

  it('shows the server’s own refusal rather than a generic failure', async () => {
    saveAllocations.mockRejectedValue(
      Object.assign(new Error('These allocations come to more than the approved budget.'), {
        name: 'ApiError',
      }),
    );

    await renderWithProviders(<MediaPlannerPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Edit allocation' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add a platform' }));
    await userEvent.click(screen.getByRole('button', { name: 'Save allocation' }));

    /*
     * A budget refusal explains what to do instead. Replacing it with "something went
     * wrong" throws away the only useful part.
     */
    expect(
      await screen.findByText(/could not be saved|more than the approved budget/),
    ).toBeInTheDocument();
  });

  it('drafts a new budget without disturbing the selected one', async () => {
    createBudget.mockResolvedValue(budget({ id: 'budget-new', name: 'Next quarter' }));
    await renderWithProviders(<MediaPlannerPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'New budget' }));

    const name = screen.getByLabelText('Name');
    await userEvent.type(name, 'Next quarter');
    const amount = screen.getByLabelText(/Amount/);
    await userEvent.type(amount, '90000');
    await userEvent.click(screen.getByRole('button', { name: 'Alhambra Retail' }));
    await userEvent.click(screen.getByRole('button', { name: 'Save budget' }));

    await waitFor(() => expect(createBudget).toHaveBeenCalledTimes(1));
    // Created, not updated: the open draft is a new budget, not an edit of the selected one.
    expect(updateBudget).not.toHaveBeenCalled();
    expect(createBudget.mock.calls[0]?.[0]).toMatchObject({
      name: 'Next quarter',
      amount: '90000',
      client_id: 'client-1',
    });
  });
});
