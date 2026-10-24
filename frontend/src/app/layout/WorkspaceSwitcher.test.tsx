import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { WorkspaceSwitcher } from './WorkspaceSwitcher';

const navigated = vi.hoisted(() => vi.fn());
const switched = vi.hoisted(() => vi.fn());
const state = vi.hoisted(() => ({
  workspaces: [] as Array<{
    id: string;
    name: string;
    type: string;
    role: string;
    role_name: string;
  }>,
}));

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
        name: 'Sara',
        email: 'sara@example.test',
        workspaces: state.workspaces,
        active_workspace: state.workspaces[0] ?? null,
        permissions: [],
        must_choose_password: false,
      },
      can: () => true,
    }),
    useSwitchWorkspaceMutation: () => ({
      mutate: (id: string, options?: { onSuccess?: () => void }) => {
        switched(id);
        options?.onSuccess?.();
      },
      isPending: false,
      isError: false,
    }),
  };
});

const AGENCY = { id: 'w1', name: 'Smart Code', type: 'agency', role: 'owner', role_name: 'Owner' };
const BRAND = {
  id: 'w2',
  name: 'Alhambra In-house',
  type: 'brand',
  role: 'media-buyer',
  role_name: 'Media buyer',
};

describe('WorkspaceSwitcher', () => {
  beforeEach(() => {
    navigated.mockReset();
    switched.mockReset();
    state.workspaces = [AGENCY, BRAND];
  });

  it('is absent for somebody with one workspace', async () => {
    state.workspaces = [AGENCY];
    await renderWithProviders(<WorkspaceSwitcher />);

    expect(screen.queryByRole('button', { name: /Switch workspace/ })).not.toBeInTheDocument();
  });

  it('names the current workspace and lists each with its type and the role held there', async () => {
    const user = userEvent.setup();

    await renderWithProviders(<WorkspaceSwitcher />);
    await user.click(
      screen.getByRole('button', { name: 'Workspace: Smart Code. Switch workspace' }),
    );

    expect(await screen.findByRole('menuitem', { name: /Alhambra In-house/ })).toHaveTextContent(
      'Brand · Media buyer',
    );
    expect(screen.getByRole('menuitem', { name: /Smart Code/ })).toHaveTextContent('(current)');
  });

  it('switches through the server and lands on the new workspace’s home', async () => {
    const user = userEvent.setup();

    await renderWithProviders(<WorkspaceSwitcher />);
    await user.click(screen.getByRole('button', { name: /Switch workspace/ }));
    await user.click(await screen.findByRole('menuitem', { name: /Alhambra In-house/ }));

    expect(switched).toHaveBeenCalledWith('w2');
    expect(navigated).toHaveBeenCalledWith('/');
  });

  it('does nothing when the current workspace is chosen again', async () => {
    const user = userEvent.setup();

    await renderWithProviders(<WorkspaceSwitcher />);
    await user.click(screen.getByRole('button', { name: /Switch workspace/ }));
    await user.click(await screen.findByRole('menuitem', { name: /Smart Code/ }));

    expect(switched).not.toHaveBeenCalled();
  });
});
