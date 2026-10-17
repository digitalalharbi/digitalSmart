import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { BudgetControlPage } from './BudgetControlPage';

const get = vi.hoisted(() => vi.fn());

vi.mock('@/shared/api/client', async () => ({
  ...(await vi.importActual<Record<string, unknown>>('@/shared/api/client')),
  api: { get: (...args: unknown[]) => get(...args) },
}));

const rows = [
  {
    id: 'b1',
    name: 'Q2 Retail',
    client: { id: 'c1', name: 'Alhambra' },
    project: null,
    currency: 'SAR',
    approved_minor: 5_000_000,
    allocated_minor: 2_000_000,
    unallocated_minor: 3_000_000,
    spent_minor: 4_900_000,
    remaining_minor: 100_000,
    spend_unavailable_reason: null,
    pacing: {
      status: 'overspending',
      status_label: 'Overspending',
      variance_percent: 0.24,
      utilisation: 0.98,
    },
    risks: [
      { risk: 'overspend', label: 'Spending ahead of plan', audience: ['finance'] },
      { risk: 'unallocated', label: 'Money not yet allocated', audience: ['finance'] },
    ],
  },
  {
    id: 'b2',
    name: 'Mixed',
    client: { id: 'c2', name: 'Najd' },
    project: null,
    currency: 'SAR',
    approved_minor: 1_000_000,
    allocated_minor: 1_000_000,
    unallocated_minor: 0,
    spent_minor: null,
    remaining_minor: null,
    spend_unavailable_reason:
      'Spend covers a currency other than the budget’s, so it cannot be totalled.',
    pacing: null,
    risks: [],
  },
];

describe('BudgetControlPage', () => {
  beforeEach(() => {
    get.mockReset();
    get.mockResolvedValue({
      data: {
        data: rows,
        meta: {
          totals: [
            {
              currency: 'SAR',
              approved_minor: 6_000_000,
              allocated_minor: 3_000_000,
              spent_minor: 4_900_000,
              unreported: 1,
              at_risk: 1,
            },
          ],
          count: 2,
          risks: [{ risk: 'overspend', label: 'Spending ahead of plan', audience: [] }],
        },
      },
    });
  });

  it('shows every risk a budget carries, not just one', async () => {
    await renderWithProviders(<BudgetControlPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Breakdown' }));
    const row = (await screen.findByText('Q2 Retail')).closest('tr') as HTMLElement;

    expect(within(row).getByText('Spending ahead of plan')).toBeInTheDocument();
    expect(within(row).getByText('Money not yet allocated')).toBeInTheDocument();
  });

  /* Null is "cannot be totalled", explained — and never drawn as a zero. */
  it('explains a spend it could not total instead of showing zero', async () => {
    await renderWithProviders(<BudgetControlPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Breakdown' }));
    const row = (await screen.findByText('Mixed')).closest('tr') as HTMLElement;

    expect(within(row).getByText(/cannot be totalled/)).toBeInTheDocument();
    /* Spent and remaining are both a dash — two cells, neither a number. */
    expect(within(row).getAllByText('—')).toHaveLength(2);
  });

  it('flags in the totals that an unreported budget is not counted', async () => {
    await renderWithProviders(<BudgetControlPage />);

    expect(await screen.findByText(/Not counted: 1 budget/)).toBeInTheDocument();
  });
});
