import type * as Client from '@/shared/api/client';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { AccessRequestsPage } from './AccessRequestsPage';
import { inbox, row } from './fixtures.test-helpers';

const state = vi.hoisted(() => ({
  can: true,
  get: vi.fn<(url: string) => Promise<{ data: unknown }>>(),
  put: vi.fn<(url: string, body: unknown) => Promise<{ data: unknown }>>(),
}));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: { usersInvite: 'users.invite' },
  useSession: () => ({ can: () => state.can }),
}));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<typeof Client>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      get: (url: string) => state.get(url),
      put: (url: string, body: unknown) => state.put(url, body),
      post: vi.fn(),
    },
  };
});

const figures = {
  days: 30,
  new_requests: 2,
  contacted: 1,
  qualified: 0,
  approved: 0,
  rejected: 0,
  median_first_response_seconds: 600,
  sla_judged: 0,
  sla_compliance: null,
  invitations_made: 0,
  invitations_accepted: 0,
  invitation_acceptance: null,
  response_minutes: 60,
};

function serve(
  rows = [
    row(),
    row({ id: 'r2', name: 'Omar Saleh', company: null, status: 'contacted', sla: null }),
  ],
) {
  state.get.mockImplementation((url: string) =>
    Promise.resolve({ data: url.includes('/figures') ? { data: figures } : inbox(rows) }),
  );
}

describe('AccessRequestsPage', () => {
  beforeEach(() => {
    state.can = true;
    state.get.mockReset();
    state.put.mockReset().mockResolvedValue({ data: {} });
    serve();
  });

  it('lists the new requests first, each with its kind, status and response-time state', async () => {
    await renderWithProviders(<AccessRequestsPage />);

    const list = await screen.findByRole('list', { name: 'New' });
    const card = within(list).getByText('Noura Al-Harbi').closest('li');

    expect(card).not.toBeNull();
    expect(within(card as HTMLElement).getByText('Account request')).toBeInTheDocument();
    expect(within(card as HTMLElement).getByText('Within response time')).toBeInTheDocument();
    expect(within(card as HTMLElement).getByText('WhatsApp')).toBeInTheDocument();
    expect(within(card as HTMLElement).getByText('Unassigned')).toBeInTheDocument();
    expect(screen.queryByText('Omar Saleh')).not.toBeInTheDocument();
  });

  it('moves between the states with tabs that carry their counts', async () => {
    const user = userEvent.setup();

    await renderWithProviders(<AccessRequestsPage />);
    await screen.findByText('Noura Al-Harbi');
    await user.click(screen.getByRole('tab', { name: /Needs follow-up/ }));

    expect(await screen.findByText('Omar Saleh')).toBeInTheDocument();
    expect(screen.queryByText('Noura Al-Harbi')).not.toBeInTheDocument();
  });

  it('shows the figures with their denominators, and "no data" instead of a rate over nothing', async () => {
    await renderWithProviders(<AccessRequestsPage />);

    expect(await screen.findByText('Median first try')).toBeInTheDocument();
    expect(screen.getByText('10 minutes')).toBeInTheDocument();
    expect(screen.getAllByText('No data yet').length).toBeGreaterThanOrEqual(2);
  });

  it('saves the response time the workspace promises', async () => {
    const user = userEvent.setup();

    await renderWithProviders(<AccessRequestsPage />);
    await user.selectOptions(await screen.findByLabelText('Respond within'), '30');

    expect(state.put).toHaveBeenCalledWith('/access-requests/settings', {
      response_minutes: 30,
      default_owner_id: null,
    });
  });

  it('says so when nothing is in a state', async () => {
    serve([]);
    await renderWithProviders(<AccessRequestsPage />);

    expect(await screen.findByText('Nothing here')).toBeInTheDocument();
  });

  it('refuses a reader who may not answer requests', async () => {
    state.can = false;
    await renderWithProviders(<AccessRequestsPage />);

    expect(await screen.findByText(/do not have permission/)).toBeInTheDocument();
  });
});
