import type * as Client from '@/shared/api/client';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { RequestAccessPage } from './RequestAccessPage';

const state = vi.hoisted(() => ({
  post: vi.fn<(url: string, body: unknown) => Promise<{ data: unknown }>>(),
}));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<typeof Client>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      get: () => Promise.resolve({ data: { data: { open: true } } }),
      post: (url: string, body: unknown) => state.post(url, body),
    },
  };
});

describe('RequestAccessPage', () => {
  beforeEach(() => {
    state.post.mockReset().mockResolvedValue({ data: {} });
  });

  it('asks an account request what it needs, and sends the preferred way to be reached', async () => {
    const user = userEvent.setup();

    await renderWithProviders(<RequestAccessPage />, { route: '/request-access' });
    await user.type(await screen.findByLabelText(/Full name/), 'Noura');
    await user.type(screen.getByLabelText(/^Email/), 'noura@example.test');
    await user.selectOptions(screen.getByLabelText('Best way to reach you'), 'whatsapp');
    await user.click(screen.getByRole('button', { name: 'Send the request' }));

    expect(state.post).toHaveBeenCalledWith(
      '/public/access-requests',
      expect.objectContaining({
        kind: 'account_access',
        preferred_contact: 'whatsapp',
        need: 'self_managed',
      }),
    );
    expect(await screen.findByText('Your request has been received')).toBeInTheDocument();
  });

  it('opens as a question at /contact, and asks an enquiry nothing about an account', async () => {
    const user = userEvent.setup();

    await renderWithProviders(<RequestAccessPage />, { route: '/contact' });
    await screen.findByLabelText(/Full name/);

    expect(screen.getByRole('heading', { level: 1, name: 'Contact us' })).toBeInTheDocument();
    expect(screen.queryByText('What do you need?')).not.toBeInTheDocument();

    await user.type(screen.getByLabelText(/Full name/), 'Omar');
    await user.type(screen.getByLabelText(/^Email/), 'omar@example.test');
    await user.click(screen.getByRole('button', { name: 'Send the request' }));

    expect(state.post).toHaveBeenCalledWith(
      '/public/access-requests',
      expect.objectContaining({ kind: 'general_enquiry', need: null }),
    );
  });
});
