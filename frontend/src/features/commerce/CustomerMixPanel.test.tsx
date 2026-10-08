import { describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { CustomerMixPanel } from './CustomerMixPanel';
import type { CustomerMix } from './types';

const buckets: CustomerMix['buckets'] = [
  { recency: 'new', label: 'New to this store' },
  { recency: 'returning', label: 'Bought before' },
  { recency: 'unknown', label: 'Started before this store was connected' },
];

function mix(overrides: Partial<CustomerMix> = {}): CustomerMix {
  return {
    new_customers: 4,
    returning_customers: 5,
    unknown_customers: 1,
    new_revenue_minor: 40_000,
    returning_revenue_minor: 50_000,
    unknown_revenue_minor: 10_000,
    new_share: 0.4,
    unavailable_reason: null,
    buckets,
    ...overrides,
  };
}

function rowFor(label: string) {
  return within(screen.getByRole('row', { name: new RegExp(label) }));
}

describe('customer mix panel', () => {
  it('names all three buckets, including the one that is empty', async () => {
    await renderWithProviders(
      <CustomerMixPanel
        mix={mix({ unknown_customers: 0, unknown_revenue_minor: 0 })}
        currency="SAR"
        locale="en"
      />,
    );

    for (const bucket of buckets) {
      expect(screen.getByRole('rowheader', { name: bucket.label })).toBeInTheDocument();
    }
  });

  it('reports the new share as a percentage of the period', async () => {
    await renderWithProviders(<CustomerMixPanel mix={mix()} currency="SAR" locale="en" />);

    expect(screen.getByTestId('customer-mix-share')).toHaveTextContent(
      '40% of revenue from new customers',
    );
  });

  it('shows each bucket its customers and its revenue', async () => {
    await renderWithProviders(<CustomerMixPanel mix={mix()} currency="SAR" locale="en" />);

    const returning = rowFor('Bought before');

    expect(returning.getByText('5')).toBeInTheDocument();
    expect(returning.getByText(/500/)).toBeInTheDocument();
  });

  /*
   * The point of the whole slice. A withheld share is replaced by the server's sentence, and no percentage is
   * invented to fill the space — a reader who sees a number assumes somebody measured it.
   */
  it('puts the reason where the share would have been when the server withholds it', async () => {
    await renderWithProviders(
      <CustomerMixPanel
        mix={mix({
          new_share: null,
          unavailable_reason:
            'Too many of this period’s customers were buying before this store was connected.',
        })}
        currency="SAR"
        locale="en"
      />,
    );

    expect(screen.getByText(/buying before this store was connected/)).toBeInTheDocument();
    expect(screen.queryByTestId('customer-mix-share')).not.toBeInTheDocument();

    /*
     * And nowhere else either. The withheld share is the new bucket's share of revenue, so any percentage on this
     * panel would be that figure recomputed — this is the assertion that keeps the table from leaking it.
     */
    expect(screen.queryByText(/%/)).not.toBeInTheDocument();
  });

  it('does not claim a nought per cent share when nothing was sold', async () => {
    await renderWithProviders(
      <CustomerMixPanel
        mix={{
          new_customers: 0,
          returning_customers: 0,
          unknown_customers: 0,
          new_revenue_minor: 0,
          returning_revenue_minor: 0,
          unknown_revenue_minor: 0,
          new_share: null,
          unavailable_reason: 'Nothing was sold in this period, so there is no mix to report.',
          buckets,
        }}
        currency="SAR"
        locale="en"
      />,
    );

    expect(screen.getByText(/Nothing was sold in this period/)).toBeInTheDocument();
    expect(screen.queryByTestId('customer-mix-share')).not.toBeInTheDocument();
  });
});
