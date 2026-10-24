import type * as Client from '@/shared/api/client';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router';
import { renderWithProviders } from '@/test/render';
import { InvitationPage } from './InvitationPage';

const state = vi.hoisted(() => ({
  post: vi.fn<(url: string, body: unknown) => Promise<{ data: unknown }>>(),
}));

let preview: Record<string, unknown> = {};

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<typeof Client>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      get: () => Promise.resolve({ data: { data: preview } }),
      post: (url: string, body: unknown) => state.post(url, body),
    },
  };
});

function page() {
  return renderWithProviders(
    <Routes>
      <Route path="/invite/:token" element={<InvitationPage />} />
    </Routes>,
    { route: `/invite/${'a'.repeat(96)}` },
  );
}

describe('InvitationPage', () => {
  beforeEach(() => {
    preview = {
      valid: true,
      name: 'Noura',
      email: 'noura@example.test',
      needs_password: true,
    };
    state.post.mockReset().mockResolvedValue({ data: {} });
  });

  it('lets the invited person choose their own password, and says the account opens then', async () => {
    const user = userEvent.setup();

    await page();
    await user.type(await screen.findByLabelText(/Choose a password/), 'a-long-chosen-passphrase');
    await user.type(screen.getByLabelText(/Repeat the password/), 'a-long-chosen-passphrase');
    await user.click(screen.getByRole('button', { name: 'Accept and open my account' }));

    expect(state.post).toHaveBeenCalledWith(`/public/invitations/${'a'.repeat(96)}/accept`, {
      password: 'a-long-chosen-passphrase',
      password_confirmation: 'a-long-chosen-passphrase',
    });
    expect(await screen.findByText('Your account is ready')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Sign in' })).toBeInTheDocument();
  });

  it('does not submit two different passwords', async () => {
    const user = userEvent.setup();

    await page();
    await user.type(await screen.findByLabelText(/Choose a password/), 'a-long-chosen-passphrase');
    await user.type(screen.getByLabelText(/Repeat the password/), 'something-else-entirely');

    expect(screen.getByText('The two passwords are different.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Accept and open my account' })).toBeDisabled();
    expect(state.post).not.toHaveBeenCalled();
  });

  it('asks for no password from somebody who already has an account', async () => {
    preview = {
      valid: true,
      name: 'Noura',
      email: 'noura@example.test',
      needs_password: false,
    };
    const user = userEvent.setup();

    await page();
    await user.click(await screen.findByRole('button', { name: 'Accept and open my account' }));

    expect(screen.queryByLabelText(/Choose a password/)).not.toBeInTheDocument();
    expect(state.post).toHaveBeenCalledWith(`/public/invitations/${'a'.repeat(96)}/accept`, {});
  });

  it('says the same thing for any link that is not a live invitation', async () => {
    preview = { valid: false };
    await page();

    expect(await screen.findByText('This invitation is not valid')).toBeInTheDocument();
    expect(screen.queryByLabelText(/Choose a password/)).not.toBeInTheDocument();
  });
});
