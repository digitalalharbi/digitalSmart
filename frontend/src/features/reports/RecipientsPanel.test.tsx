import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { RecipientsPanel } from './RecipientsPanel';
import type { RecipientList, ReportRecipient } from './recipients';

const state = vi.hoisted((): { list: unknown; added: unknown[]; changed: unknown[] } => ({
  list: undefined,
  added: [],
  changed: [],
}));

vi.mock('./recipients', () => ({
  useRecipientsQuery: () => ({ isError: false, data: state.list }),
  useAddRecipientMutation: () => ({
    isPending: false,
    isError: false,
    error: null,
    mutate: (draft: unknown) => state.added.push(draft),
  }),
  useChangeRecipientMutation: () => ({
    isPending: false,
    mutate: (input: unknown) => state.changed.push(input),
  }),
}));

function recipient(overrides: Partial<ReportRecipient> = {}): ReportRecipient {
  return {
    id: 'r1',
    report_id: 'rep',
    email: 'client@customer.example',
    name: 'Noura',
    locale: 'en',
    cadence: 'weekly',
    status: 'active',
    halted_reason: null,
    consent_note: 'Asked for the weekly link in the kickoff call.',
    consented_at: '2026-11-03T09:00:00Z',
    added_by_name: 'Sara',
    deliveries: [],
    ...overrides,
  };
}

function list(overrides: Partial<RecipientList> = {}): RecipientList {
  return { recipients: [recipient()], mailConfigured: true, mailMissing: null, ...overrides };
}

describe('report email recipients', () => {
  beforeEach(() => {
    state.list = list();
    state.added = [];
    state.changed = [];
  });

  it('says plainly when this installation cannot send email, and why', async () => {
    state.list = list({ mailConfigured: false, mailMissing: 'Missing: a mail provider.' });

    await renderWithProviders(<RecipientsPanel reportId="rep" />);

    expect(screen.getByText(/Email is not sent from this installation yet/)).toBeInTheDocument();
    expect(screen.getByText('Missing: a mail provider.')).toBeInTheDocument();
  });

  it('shows the recorded consent and what the provider said, without claiming arrival', async () => {
    state.list = list({
      recipients: [
        recipient({
          deliveries: [
            {
              id: 'd1',
              period: '2026-W45',
              state: 'provider_accepted',
              detail: null,
              attempts: 1,
              attempted_at: '2026-11-04T09:15:00Z',
            },
          ],
        }),
      ],
    });

    await renderWithProviders(<RecipientsPanel reportId="rep" />);

    expect(screen.getByText(/Consent: Asked for the weekly link/)).toBeInTheDocument();
    expect(screen.getByTestId('recipient-last')).toHaveTextContent(
      '2026-W45: accepted by the mail provider (arrival is not confirmed)',
    );
    expect(screen.queryByText(/delivered/i)).not.toBeInTheDocument();
  });

  it('says a period was not sent when no provider is connected', async () => {
    state.list = list({
      recipients: [
        recipient({
          deliveries: [
            {
              id: 'd1',
              period: '2026-W45',
              state: 'awaiting_credentials',
              detail: null,
              attempts: 1,
              attempted_at: null,
            },
          ],
        }),
      ],
    });

    await renderWithProviders(<RecipientsPanel reportId="rep" />);

    expect(screen.getByTestId('recipient-last')).toHaveTextContent('not sent — no mail provider');
  });

  it('will not add a recipient without an address and a consent sentence', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<RecipientsPanel reportId="rep" />);

    const add = screen.getByRole('button', { name: 'Add recipient' });
    expect(add).toBeDisabled();

    await user.type(screen.getByLabelText(/Recipient's email/), 'new@customer.example');
    expect(add).toBeDisabled();

    await user.type(
      screen.getByLabelText(/How did they agree/),
      'Confirmed by email on 4 November.',
    );
    await user.click(add);

    expect(state.added).toEqual([
      expect.objectContaining({
        email: 'new@customer.example',
        cadence: 'weekly',
        consent_note: 'Confirmed by email on 4 November.',
      }),
    ]);
  });

  it('offers no resume for somebody who unsubscribed', async () => {
    state.list = list({ recipients: [recipient({ status: 'unsubscribed' })] });

    await renderWithProviders(<RecipientsPanel reportId="rep" />);

    const row = within(screen.getByRole('list', { name: 'Email this report to a client' }));

    expect(row.getByText('Unsubscribed by the recipient')).toBeInTheDocument();
    expect(row.queryByRole('button', { name: 'Resume' })).not.toBeInTheDocument();
    expect(row.queryByRole('button', { name: 'Pause' })).not.toBeInTheDocument();
  });
});
