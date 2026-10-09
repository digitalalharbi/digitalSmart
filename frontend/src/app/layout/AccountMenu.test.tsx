import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { AccountMenu } from './AccountMenu';

const navigated = vi.hoisted(() => vi.fn());
const state = vi.hoisted(() => ({ logoutFails: false }));
const logout = vi.hoisted(() => vi.fn());

vi.mock('react-router', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('react-router');

  return { ...actual, useNavigate: () => navigated };
});

vi.mock('@/features/auth', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/auth');

  return {
    ...actual,
    useSession: () => ({
      user: {
        id: 'me',
        name: 'Sara Idris',
        email: 'sara@agency.com',
        workspaces: [{ id: 'w1', name: 'Smart Code', role: 'owner' }],
        active_workspace: { id: 'w1', name: 'Smart Code', role: 'owner' },
        permissions: [],
        must_choose_password: false,
      },
      isLoading: false,
      isAuthenticated: true,
      can: () => true,
    }),
    useLogoutMutation: () => ({
      mutate: (_input: undefined, options?: { onSuccess?: () => void }) => {
        logout();

        if (!state.logoutFails) {
          options?.onSuccess?.();
        }
      },
      isPending: false,
      isError: state.logoutFails,
    }),
    useSwitchWorkspaceMutation: () => ({ mutate: vi.fn(), isPending: false }),
  };
});

beforeEach(() => {
  navigated.mockReset();
  logout.mockReset();
  state.logoutFails = false;
});

/*
 * A sign-out that did not happen must not look like one that did.
 *
 * An end-to-end run under load caught the real version: the request failed with no response, the interface
 * navigated to the sign-in screen anyway, and the back route to a client list rendered three clients — because
 * the session was never ended.
 */
describe('signing out', () => {
  it('goes to the sign-in screen once the server has ended the session', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<AccountMenu />);

    await user.click(screen.getByRole('button', { name: 'Account and workspace' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Sign out' }));

    await waitFor(() => expect(logout).toHaveBeenCalled());
    expect(navigated).toHaveBeenCalledWith('/login', { replace: true });
  });

  it('stays put and says so when the request never got through', async () => {
    state.logoutFails = true;
    const user = userEvent.setup();
    await renderWithProviders(<AccountMenu />);

    await user.click(screen.getByRole('button', { name: 'Account and workspace' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Sign out' }));

    await waitFor(() => expect(logout).toHaveBeenCalled());
    /* The session is still live, so the one dishonest thing available is a sign-in screen. */
    expect(navigated).not.toHaveBeenCalled();
  });

  it('shows the failure in the menu it was clicked in', async () => {
    state.logoutFails = true;
    const user = userEvent.setup();
    await renderWithProviders(<AccountMenu />);

    await user.click(screen.getByRole('button', { name: 'Account and workspace' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('you are still signed in');
  });
});
