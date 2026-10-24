import type * as Client from '@/shared/api/client';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router';
import { renderWithProviders } from '@/test/render';
import { AccessRequestDetailPage } from './AccessRequestDetailPage';
import { inbox, row } from './fixtures.test-helpers';
import type { AccessDetail } from './types';

const state = vi.hoisted(() => ({
  post: vi.fn<(url: string, body?: unknown) => Promise<{ data: unknown }>>(),
}));

let current: unknown = null;

vi.mock('@/features/auth', () => ({
  PERMISSIONS: { usersInvite: 'users.invite' },
  useSession: () => ({ can: () => true }),
}));

vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({
    data: { data: [{ id: 'p1', name: 'Seasonal', client: { id: 'c1', name: 'Alhambra' } }] },
  }),
}));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<typeof Client>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      get: (url: string) =>
        Promise.resolve({
          data: url === '/access-requests' ? inbox([]) : { data: current, meta: inbox([]).meta },
        }),
      post: (url: string, body?: unknown) => state.post(url, body),
    },
  };
});

function detail(overrides: Partial<AccessDetail> = {}): AccessDetail {
  return { ...row(), events: [], linked: [], invitation: null, ...overrides };
}

function open() {
  return renderWithProviders(
    <Routes>
      <Route path="/access-requests/:id" element={<AccessRequestDetailPage />} />
    </Routes>,
    { route: '/access-requests/r1' },
  );
}

describe('AccessRequestDetailPage', () => {
  beforeEach(() => {
    current = detail();
    state.post.mockReset().mockResolvedValue({ data: { data: {} } });
  });

  it('records a contact attempt by hand and says plainly that nothing is sent from here', async () => {
    const user = userEvent.setup();

    await open();
    await user.click(await screen.findByRole('button', { name: 'Mark email sent' }));

    expect(state.post).toHaveBeenCalledWith('/access-requests/r1/contact', {
      action: 'email_sent',
      note: null,
    });
    expect(screen.getByText(/Email delivery: awaiting credentials/)).toBeInTheDocument();
    expect(screen.getByText(/nothing is sent from here/)).toBeInTheDocument();
  });

  it('approves an account request without promising an account', async () => {
    const user = userEvent.setup();

    await open();
    await user.click(await screen.findByRole('button', { name: 'Approve' }));

    expect(state.post).toHaveBeenCalledWith('/access-requests/r1/approve', { note: null });
    expect(screen.getByText(/Approving does not create an account/)).toBeInTheDocument();
  });

  it('offers no approval for an enquiry', async () => {
    current = detail({ kind: 'general_enquiry', need: null });
    await open();

    await screen.findByRole('button', { name: 'Reject' });
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  });

  it('will not reject without a reason, and says the reason stays internal', async () => {
    const user = userEvent.setup();

    await open();
    await user.click(await screen.findByRole('button', { name: 'Reject' }));

    expect(screen.getByRole('button', { name: 'Reject the request' })).toBeDisabled();
    expect(screen.getByText('Internal. Never shown to the person.')).toBeInTheDocument();

    await user.type(screen.getByLabelText(/^Reason/), 'Competitor of a client');
    await user.click(screen.getByRole('button', { name: 'Reject the request' }));

    expect(state.post).toHaveBeenCalledWith('/access-requests/r1/reject', {
      reason: 'Competitor of a client',
      message: null,
    });
  });

  it('shows the invitation link once, hands over nothing by itself, and records "sent" only when told', async () => {
    current = detail({ status: 'approved', reviewed_at: new Date().toISOString() });
    state.post.mockImplementation((url) =>
      Promise.resolve({
        data: url.endsWith('/invitation')
          ? {
              data: {
                path: `/invite/${'b'.repeat(96)}`,
                hint: 'bbbbbbbbbbbb',
                expires_at: new Date().toISOString(),
              },
            }
          : { data: {} },
      }),
    );
    const user = userEvent.setup();

    await open();
    await user.click(await screen.findByRole('button', { name: 'Make the invitation' }));

    expect(await screen.findByTestId('invitation-link')).toHaveTextContent(
      `/invite/${'b'.repeat(96)}`,
    );
    expect(screen.getByText(/shown once/)).toBeInTheDocument();
    expect(screen.getByText(/awaiting credentials: send the link yourself/)).toBeInTheDocument();
    expect(state.post).not.toHaveBeenCalledWith('/access-requests/r1/invitation/shared', undefined);

    await user.click(screen.getByRole('button', { name: 'I have sent it' }));

    expect(state.post).toHaveBeenCalledWith('/access-requests/r1/invitation/shared', undefined);
  });

  it('shows each step after approval with the time it happened, and a dash where it has not', async () => {
    current = detail({
      status: 'approved',
      reviewed_at: new Date().toISOString(),
      invited_at: new Date().toISOString(),
    });
    await open();

    const steps = await screen.findByRole('region', { name: 'After approval' });

    expect(steps).toHaveTextContent('Invitation made');
    expect(steps).toHaveTextContent('Invitation accepted—');
    expect(steps).toHaveTextContent('First sign-in—');
  });
});
