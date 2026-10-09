import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ProjectsPage } from './ProjectsPage';
import type { Project } from './types';

const projectsQuery = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

const state = vi.hoisted(() => ({
  members: [] as {
    user_id: string;
    name: string;
    email: string;
    role_slug: string;
    role_name: string;
  }[],
  assignable: [] as { user_id: string; name: string; role_slug: string; role_name: string }[],
  assigned: null as Record<string, unknown> | null,
  removed: null as Record<string, unknown> | null,
}));

vi.mock('./api', () => ({
  useProjectsQuery: () => projectsQuery(),
  useCreateProjectMutation: () => ({
    mutateAsync: vi.fn(),
    isPending: false,
    error: null,
    reset: vi.fn(),
  }),
  useUpdateProjectMutation: () => ({
    mutateAsync: vi.fn(),
    isPending: false,
    error: null,
    reset: vi.fn(),
  }),
  useChangeProjectStatusMutation: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteProjectMutation: () => ({ mutate: vi.fn(), isPending: false }),
  useProjectMembersQuery: () => ({ data: state.members, isPending: false }),
  useAssignableMembersQuery: () => ({ data: state.assignable, isPending: false }),
  useAssignMemberMutation: () => ({
    mutate: (input: Record<string, unknown>) => {
      state.assigned = input;
    },
    isPending: false,
    error: null,
  }),
  useRemoveMemberMutation: () => ({
    mutate: (input: Record<string, unknown>) => {
      state.removed = input;
    },
    isPending: false,
    error: null,
  }),
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({ data: { data: [], meta: null } }),
}));

vi.mock('@/features/auth', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/auth');
  return {
    ...actual,
    useSession: () => ({
      user: undefined,
      isLoading: false,
      isAuthenticated: true,
      can: (permission: string) => permissions.current.includes(permission),
    }),
  };
});

function project(overrides: Partial<Project> = {}): Project {
  return {
    id: 'p1',
    client: { id: 'c1', name: 'Alhambra Retail' },
    name: 'Ramadan Campaign',
    slug: 'ramadan-campaign',
    status: 'active',
    currency: 'SAR',
    description: null,
    starts_on: '2026-03-01',
    ends_on: '2026-04-15',
    is_demo: false,
    member_count: 4,
    is_assigned: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function page(items: Project[]) {
  return {
    data: {
      data: items,
      meta: { total: items.length, page: 1, per_page: 25, last_page: 1, has_more: false },
    },
    isPending: false,
    isError: false,
    error: null,
  };
}

describe('ProjectsPage', () => {
  beforeEach(() => {
    projectsQuery.mockReset();
    permissions.current = ['projects.view_all'];
  });

  it('shows the client, schedule and team size for each project', async () => {
    projectsQuery.mockReturnValue(page([project()]));
    await renderWithProviders(<ProjectsPage />);

    const row = screen.getByRole('row', { name: /Ramadan Campaign/ });
    expect(within(row).getByText('Alhambra Retail')).toBeInTheDocument();
    expect(within(row).getByText('Active')).toBeInTheDocument();
    expect(within(row).getByText('4')).toBeInTheDocument();
    expect(within(row).getByText(/2026-03-01/)).toBeInTheDocument();
  });

  it('says the list is limited to assignments when the role cannot see all', async () => {
    permissions.current = ['projects.view_assigned'];
    projectsQuery.mockReturnValue(page([project({ is_assigned: true })]));
    await renderWithProviders(<ProjectsPage />);

    expect(screen.getByText('Showing the projects you are assigned to.')).toBeInTheDocument();
    expect(screen.getByText('Assigned to you')).toBeInTheDocument();
  });

  it('explains an empty list differently for an unassigned viewer', async () => {
    permissions.current = ['projects.view_assigned'];
    projectsQuery.mockReturnValue(page([]));
    await renderWithProviders(<ProjectsPage />);

    // Not "no projects exist" — they just cannot see any yet.
    expect(screen.getByText('You are not assigned to any project yet')).toBeInTheDocument();
    expect(screen.getByText(/A project manager assigns people/)).toBeInTheDocument();
  });

  it('says the workspace is empty when a full-visibility role sees nothing', async () => {
    projectsQuery.mockReturnValue(page([]));
    await renderWithProviders(<ProjectsPage />);

    expect(screen.getByText('No projects yet')).toBeInTheDocument();
  });

  it('conveys whether a project may currently spend', async () => {
    projectsQuery.mockReturnValue(page([project({ status: 'paused' })]));
    await renderWithProviders(<ProjectsPage />);

    expect(screen.getByTitle('Campaigns under this project may not spend.')).toBeInTheDocument();
  });

  it('offers only the transitions the domain permits', async () => {
    permissions.current = ['projects.view_all', 'projects.update'];
    projectsQuery.mockReturnValue(page([project({ status: 'completed' })]));
    const user = userEvent.setup();
    await renderWithProviders(<ProjectsPage />);

    await user.click(screen.getByRole('button', { name: /Actions for Ramadan Campaign/ }));

    const menu = await screen.findByRole('menu');
    // completed -> active | archived. Never back to planning or paused.
    expect(within(menu).getByRole('menuitem', { name: 'Active' })).toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: 'Archived' })).toBeInTheDocument();
    expect(within(menu).queryByRole('menuitem', { name: 'Planning' })).not.toBeInTheDocument();
    expect(within(menu).queryByRole('menuitem', { name: 'Paused' })).not.toBeInTheDocument();
  });

  it('hides creation from a role that cannot create', async () => {
    permissions.current = ['projects.view_assigned'];
    projectsQuery.mockReturnValue(page([project()]));
    await renderWithProviders(<ProjectsPage />);

    expect(screen.queryByRole('button', { name: 'New project' })).not.toBeInTheDocument();
  });
  state.members = [];
  state.assignable = [];
  state.assigned = null;
  state.removed = null;
});

describe('who can see a project', () => {
  /*
   * Its own reset. The first describe's `beforeEach` is scoped to that block, so without this the access lists
   * carry over between tests here — which is how the empty-project test first read a populated dialog.
   */
  beforeEach(() => {
    projectsQuery.mockReset();
    projectsQuery.mockReturnValue(page([project()]));
    state.members = [];
    state.assignable = [];
    state.assigned = null;
    state.removed = null;
  });

  /*
   * Project membership is the only thing in this product that grants sight of a client's work, and the page
   * showed the number of people on a project with no way to change it: the figure that decides access was
   * read-only.
   */
  it('opens the access list from the member count', async () => {
    permissions.current = ['projects.view_all', 'projects.manage_members'];
    state.members = [
      {
        user_id: 'user-2',
        name: 'Noura Al-Shehri',
        email: 'noura@example.com',
        role_slug: 'creative',
        role_name: 'Creative',
      },
    ];
    state.assignable = [
      {
        user_id: 'user-3',
        name: 'Omar Al-Qahtani',
        role_slug: 'media-buyer',
        role_name: 'Media buyer',
      },
    ];

    await renderWithProviders(<ProjectsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Manage access' }));

    const dialog = screen.getByRole('dialog');

    expect(
      within(within(dialog).getByRole('list', { name: 'People on this project' })).getByText(
        'Noura Al-Shehri',
      ),
    ).toBeInTheDocument();
    /* And it says what being on a project means, rather than leaving a reader to infer it from a list. */
    expect(within(dialog).getByText(/grants sight of the client/)).toBeInTheDocument();
  });

  it('grants access in the role the colleague already holds', async () => {
    permissions.current = ['projects.view_all', 'projects.manage_members'];
    state.assignable = [
      {
        user_id: 'user-3',
        name: 'Omar Al-Qahtani',
        role_slug: 'media-buyer',
        role_name: 'Media buyer',
      },
    ];

    await renderWithProviders(<ProjectsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Manage access' }));

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Add somebody' }), 'user-3');
    await userEvent.click(screen.getByRole('button', { name: 'Grant access' }));

    expect(state.assigned).toEqual({
      projectId: 'p1',
      userId: 'user-3',
      /*
       * Their workspace role, not a second choice on the form: people are almost always on a project in the
       * role they hold in the agency.
       */
      role: 'media-buyer',
    });
  });

  /*
   * An empty project is not a neutral state — nobody can reach this client's work through it — so it is said
   * rather than left as a blank list.
   */
  it('says what an empty project means', async () => {
    permissions.current = ['projects.view_all', 'projects.manage_members'];

    await renderWithProviders(<ProjectsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Manage access' }));

    expect(screen.getByText('Nobody is on this project')).toBeInTheDocument();
  });

  it('shows the list read-only to somebody who may not manage members', async () => {
    permissions.current = ['projects.view_all'];
    state.members = [
      {
        user_id: 'user-2',
        name: 'Noura Al-Shehri',
        email: 'noura@example.com',
        role_slug: 'creative',
        role_name: 'Creative',
      },
    ];

    await renderWithProviders(<ProjectsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Manage access' }));

    const dialog = screen.getByRole('dialog');

    expect(
      within(within(dialog).getByRole('list', { name: 'People on this project' })).getByText(
        'Noura Al-Shehri',
      ),
    ).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
    expect(within(dialog).queryByRole('combobox')).not.toBeInTheDocument();
  });
});
