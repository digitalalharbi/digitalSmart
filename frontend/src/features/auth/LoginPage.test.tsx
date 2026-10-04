import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ApiError } from '@/shared/api/client';
import { LoginPage } from './LoginPage';

const login = vi.hoisted(() => vi.fn());
const navigate = vi.hoisted(() => vi.fn());

vi.mock('./api', () => ({
  useLoginMutation: () => ({
    mutateAsync: login,
    isPending: false,
    error: errorRef.current,
  }),
}));

vi.mock('react-router', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('react-router');
  return { ...actual, useNavigate: () => navigate };
});

const errorRef: { current: unknown } = { current: null };

describe('LoginPage', () => {
  beforeEach(() => {
    login.mockReset();
    navigate.mockReset();
    errorRef.current = null;
  });

  it('submits the credentials and lands the user in the app', async () => {
    login.mockResolvedValue({ id: 'u1' });
    const user = userEvent.setup();
    await renderWithProviders(<LoginPage />);

    await user.type(screen.getByLabelText(/email/i), 'buyer@agency.com');
    await user.type(screen.getByLabelText(/password/i), 'a-real-password');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => {
      expect(login).toHaveBeenCalledWith({
        email: 'buyer@agency.com',
        password: 'a-real-password',
        remember: false,
      });
    });
    expect(navigate).toHaveBeenCalledWith('/', { replace: true });
  });

  it('does not call the API when the email is malformed', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<LoginPage />);

    await user.type(screen.getByLabelText(/email/i), 'not-an-email');
    await user.type(screen.getByLabelText(/password/i), 'a-real-password');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Enter a valid email address.');
    expect(login).not.toHaveBeenCalled();
  });

  it("shows the server's message verbatim rather than interpreting it", async () => {
    // The backend deliberately returns one message for wrong password and
    // unknown address; the UI must not add a distinction of its own.
    errorRef.current = new ApiError('These credentials do not match our records.', 422);
    await renderWithProviders(<LoginPage />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'These credentials do not match our records.',
    );
  });

  it('passes the remember preference through', async () => {
    login.mockResolvedValue({ id: 'u1' });
    const user = userEvent.setup();
    await renderWithProviders(<LoginPage />);

    await user.type(screen.getByLabelText(/email/i), 'buyer@agency.com');
    await user.type(screen.getByLabelText(/password/i), 'a-real-password');
    await user.click(screen.getByLabelText(/keep me signed in/i));
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => {
      expect(login).toHaveBeenCalledWith(expect.objectContaining({ remember: true }));
    });
  });
});
