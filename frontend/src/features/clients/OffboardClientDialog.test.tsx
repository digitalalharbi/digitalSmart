import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { OffboardClientDialog } from './OffboardClientDialog';
import type { Client } from './types';

const mutate = vi.hoisted(() => vi.fn());

vi.mock('./api', () => ({
  useOffboardingPreview: () => ({
    isPending: false,
    data: {
      projects_closing: 3,
      bindings_paused: 5,
      connections_disconnected: 1,
      portal_suspended: true,
      share_links_revoked: 2,
      open_tasks: 4,
      open_requests: 1,
    },
  }),
  useOffboardClientMutation: () => ({ mutate, isPending: false, isError: false, error: null }),
}));

const client = { id: 'c1', name: 'Alhambra Retail' } as Client;

describe('OffboardClientDialog', () => {
  beforeEach(() => mutate.mockReset());

  it('says what will stop before anything is done, and what is kept', async () => {
    await renderWithProviders(<OffboardClientDialog client={client} onClose={vi.fn()} />);

    expect(screen.getByRole('dialog', { name: 'Alhambra Retail is leaving' })).toBeInTheDocument();
    const list = screen.getByRole('list', { name: 'What will stop' });
    expect(list).toHaveTextContent('Accounts that stop syncing');
    expect(list).toHaveTextContent('Client portal suspended');
    expect(screen.getByText(/Nothing is deleted/)).toBeInTheDocument();
  });

  it('will not offboard without a reason', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<OffboardClientDialog client={client} onClose={vi.fn()} />);

    const confirm = screen.getByRole('button', { name: 'Offboard client' });
    expect(confirm).toBeDisabled();

    await user.type(screen.getByLabelText(/Reason/), 'Contract ended');
    expect(confirm).toBeEnabled();

    await user.click(confirm);
    expect(mutate).toHaveBeenCalledWith({ id: 'c1', reason: 'Contract ended' }, expect.anything());
  });
});
