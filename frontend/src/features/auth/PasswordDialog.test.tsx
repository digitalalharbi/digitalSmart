import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import type * as client from '@/shared/api/client';
import { PasswordDialog } from './PasswordDialog';

type ApiClientModule = typeof client;

const state = vi.hoisted(() => ({
  sent: null as { current_password: string; password: string } | null,
  fail: null as string | null,
}));

vi.mock('@/shared/api/client', async () => {
  const actual = await vi.importActual<ApiClientModule>('@/shared/api/client');

  return {
    ...actual,
    api: {
      post: (_url: string, body: { current_password: string; password: string }) => {
        if (state.fail !== null) {
          return Promise.reject(new actual.ApiError(state.fail, 422));
        }

        state.sent = body;

        return Promise.resolve({ data: null });
      },
    },
  };
});

beforeEach(() => {
  state.sent = null;
  state.fail = null;
});

/*
 * Queried with the asterisk, because `Field` renders the required marker inside the label and that is what a
 * screen reader reads out. Matching without it would be asserting against a name the interface does not have.
 */
async function fill(current: string, next: string, confirmation = next) {
  await userEvent.type(screen.getByLabelText('Your current password*'), current);
  await userEvent.type(screen.getByLabelText('New password*'), next);
  await userEvent.type(screen.getByLabelText('New password again*'), confirmation);
}

describe('changing your own password', () => {
  it('sends the current password with the new one', async () => {
    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    await fill('the-old-passphrase', 'a-much-longer-passphrase');
    await userEvent.click(screen.getByRole('button', { name: 'Change it' }));

    expect(state.sent).toEqual({
      current_password: 'the-old-passphrase',
      password: 'a-much-longer-passphrase',
      password_confirmation: 'a-much-longer-passphrase',
    });
  });

  /*
   * Said before it happens. A reader who is not told will wonder afterwards why their phone signed itself out —
   * and that is the behaviour they are asking for when they change a password.
   */
  it('says that every other session will be signed out, before it happens', async () => {
    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    expect(
      screen.getByText(/Every other place you are signed in will be signed out/),
    ).toBeInTheDocument();
    expect(screen.getByText(/This screen stays signed in/)).toBeInTheDocument();
  });

  it('says why the current password is being asked for', async () => {
    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    expect(
      screen.getByText(/a session can be left open on a machine that is not yours/),
    ).toBeInTheDocument();
  });

  it('will not send a mismatched confirmation', async () => {
    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    await fill('the-old-passphrase', 'a-much-longer-passphrase', 'a-much-longer-passphrasse');

    expect(screen.getByText('The two new passwords do not match.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Change it' })).toBeDisabled();
    expect(state.sent).toBeNull();
  });

  it('will not send a password shorter than the minimum', async () => {
    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    await fill('the-old-passphrase', 'short');

    expect(screen.getByText('Use at least 12 characters.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Change it' })).toBeDisabled();
  });

  /*
   * The server's own sentence, which names the thing to do about it. A generic "something went wrong" would hide
   * the one piece of information the reader needs.
   */
  it('shows the server’s refusal rather than a generic failure', async () => {
    state.fail = 'That is not your current password.';

    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    await fill('wrong-passphrase', 'a-much-longer-passphrase');
    await userEvent.click(screen.getByRole('button', { name: 'Change it' }));

    expect(await screen.findByText('That is not your current password.')).toBeInTheDocument();
  });

  it('confirms afterwards that the other sessions were ended', async () => {
    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    await fill('the-old-passphrase', 'a-much-longer-passphrase');
    await userEvent.click(screen.getByRole('button', { name: 'Change it' }));

    expect(
      await screen.findByText(/Anywhere else you were signed in has been signed out/),
    ).toBeInTheDocument();
  });

  /* Nothing reports strength: a meter is a model's opinion rendered as a fact. */
  it('offers no strength meter', async () => {
    await renderWithProviders(<PasswordDialog open onOpenChange={() => {}} />);

    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    expect(screen.queryByRole('meter')).not.toBeInTheDocument();
  });
});
