import { describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { LedgerPanel } from './LedgerPanel';
import type { RevenueLedger } from './types';

function ledger(overrides: Partial<RevenueLedger> = {}): RevenueLedger {
  return {
    currency: 'SAR',
    orders: 3,
    total_minor: 165_000,
    refunded_minor: 20_000,
    net_minor: 145_000,
    components: [
      { key: 'subtotal', amount_minor: 100_000, orders_reporting: 1, orders_counted: 3 },
      { key: 'discount', amount_minor: 10_000, orders_reporting: 1, orders_counted: 3 },
      { key: 'shipping', amount_minor: 15_000, orders_reporting: 2, orders_counted: 3 },
      { key: 'tax', amount_minor: null, orders_reporting: 0, orders_counted: 3 },
    ],
    reconciliation: { orders_checked: 0, orders_off: 0, unexplained_minor: null, reconciles: null },
    ...overrides,
  };
}

function row(name: string) {
  return within(screen.getByRole('row', { name: new RegExp(name) }));
}

describe('revenue ledger panel', () => {
  it('states what was charged, what was refunded and what is left', async () => {
    await renderWithProviders(<LedgerPanel ledger={ledger()} locale="en" />);

    expect(row('Charged to customers').getByText(/1,650/)).toBeInTheDocument();
    expect(row('Refunded').getByText(/200/)).toBeInTheDocument();
    expect(row('After refunds').getByText(/1,450/)).toBeInTheDocument();
  });

  it('says a part nobody reported was not reported, never nought', async () => {
    await renderWithProviders(<LedgerPanel ledger={ledger()} locale="en" />);

    expect(row('Tax').getByText('Not reported')).toBeInTheDocument();
    expect(row('Tax').getByText('0 of 3')).toBeInTheDocument();
    // A part that was reported shows an amount and how much of the period it covers.
    expect(row('Shipping').getByText('2 of 3')).toBeInTheDocument();
    expect(row('Shipping').queryByText('Not reported')).not.toBeInTheDocument();
  });

  it('does not claim the parts add up when no order reported enough to check', async () => {
    await renderWithProviders(<LedgerPanel ledger={ledger()} locale="en" />);

    expect(screen.getByText(/could not be checked against the total/)).toBeInTheDocument();
    expect(screen.queryByTestId('ledger-reconciles')).not.toBeInTheDocument();
  });

  it('says the parts add up only where they were checked, and how many', async () => {
    await renderWithProviders(
      <LedgerPanel
        ledger={ledger({
          reconciliation: {
            orders_checked: 2,
            orders_off: 0,
            unexplained_minor: 0,
            reconciles: true,
          },
        })}
        locale="en"
      />,
    );

    expect(screen.getByTestId('ledger-reconciles')).toHaveTextContent(
      'add up to the total on all 2 orders',
    );
  });

  it('publishes the difference when they do not add up', async () => {
    await renderWithProviders(
      <LedgerPanel
        ledger={ledger({
          reconciliation: {
            orders_checked: 2,
            orders_off: 1,
            unexplained_minor: 2_500,
            reconciles: false,
          },
        })}
        locale="en"
      />,
    );

    expect(
      screen.getByText(/On 1 of the 2 orders that reported all four parts/),
    ).toBeInTheDocument();
    expect(screen.getByText(/25\.00/)).toBeInTheDocument();
  });
});
