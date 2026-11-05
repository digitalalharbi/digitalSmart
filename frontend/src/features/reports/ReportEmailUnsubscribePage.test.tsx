import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type * as Router from 'react-router';
import { renderWithProviders } from '@/test/render';
import { ReportEmailUnsubscribePage } from './ReportEmailUnsubscribePage';

const calls = vi.hoisted((): { tokens: string[]; fails: boolean } => ({
  tokens: [],
  fails: false,
}));

vi.mock('./recipients', () => ({
  unsubscribeFromReportEmails: (token: string) => {
    calls.tokens.push(token);

    return calls.fails ? Promise.reject(new Error('down')) : Promise.resolve();
  },
}));
vi.mock('react-router', async () => ({
  ...(await vi.importActual<typeof Router>('react-router')),
  useParams: () => ({ token: 'abc123' }),
}));

describe('report email unsubscribe page', () => {
  beforeEach(() => {
    calls.tokens = [];
    calls.fails = false;
  });

  it('does nothing on arrival: a scanner opening the link stops nobody', async () => {
    await renderWithProviders(<ReportEmailUnsubscribePage />);

    expect(screen.getByRole('button', { name: 'Stop the emails' })).toBeInTheDocument();
    expect(calls.tokens).toEqual([]);
  });

  it('stops on a press and says so', async () => {
    await renderWithProviders(<ReportEmailUnsubscribePage />);

    await userEvent.setup().click(screen.getByRole('button', { name: 'Stop the emails' }));

    expect(await screen.findByRole('status')).toHaveTextContent(
      'You will not be emailed this report',
    );
    expect(calls.tokens).toEqual(['abc123']);
  });

  it('says so when the stop did not go through, and keeps the button', async () => {
    calls.fails = true;
    await renderWithProviders(<ReportEmailUnsubscribePage />);

    await userEvent.setup().click(screen.getByRole('button', { name: 'Stop the emails' }));

    expect(
      await screen.findByText('That did not go through. Please try again.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Stop the emails' })).toBeEnabled();
  });
});
