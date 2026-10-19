import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { Alert } from './alert';
import { Card, CardDescription, CardHeader, CardTitle } from './card';
import { InfoHint } from './info-hint';

const LONG =
  'These leads carried two different currencies, so their totals are not shown: a sum of two currencies is not a larger number.';

describe('Alert', () => {
  it('shows a short notice as it is', async () => {
    await renderWithProviders(<Alert tone="warning">Could not save.</Alert>);

    expect(screen.getByRole('alert')).toHaveTextContent('Could not save.');
    expect(screen.queryByRole('button', { name: 'More' })).not.toBeInTheDocument();
  });

  it('folds a long note to two lines, keeps all of it in the page, and unfolds on request', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<Alert tone="info">{LONG}</Alert>);

    expect(screen.getByText(LONG)).toHaveClass('line-clamp-2');

    await user.click(screen.getByRole('button', { name: 'More' }));

    expect(screen.getByText(LONG)).not.toHaveClass('line-clamp-2');
    expect(screen.getByRole('button', { name: 'Less' })).toHaveAttribute('aria-expanded', 'true');
  });

  it('never folds a failure: somebody has to act on it', async () => {
    await renderWithProviders(<Alert tone="danger">{LONG}</Alert>);

    expect(screen.queryByRole('button', { name: 'More' })).not.toBeInTheDocument();
  });
});

describe('InfoHint', () => {
  it('keeps the explanation in the page as the button’s description', async () => {
    await renderWithProviders(<InfoHint text="What this page is for." />);

    expect(screen.getByRole('button', { name: 'More information' })).toHaveAccessibleDescription(
      'What this page is for.',
    );
  });
});

describe('CardDescription', () => {
  it('is a hint in the corner when it is text, so a card is its title and its figures', async () => {
    await renderWithProviders(
      <Card>
        <CardHeader>
          <CardTitle>Spend</CardTitle>
          <CardDescription>Everything the platforms reported.</CardDescription>
        </CardHeader>
      </Card>,
    );

    expect(screen.getByRole('button', { name: 'More information' })).toHaveAccessibleDescription(
      'Everything the platforms reported.',
    );
  });
});
