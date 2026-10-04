import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ProjectsPage } from './ProjectsPage';
import type { Project } from './types';

const projectsQuery = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('./api', () => ({
  useProjectsQuery: () => projectsQuery(),
  useCreateProjectMutation: () => ({ mutateAsync: vi.fn(), isPending: false, error: null, reset: vi.fn() }),
  useUpdateProjectMutation: () => ({ mutateAsync: vi.fn(), isPending: false, error: null, reset: vi.fn() }),
  useChangeProjectStatusMutation: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteProjectMutation: () => ({ mutate: vi.fn(), isPending: false }),
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
    data: { data: items, meta: { total: items.length, page: 1, per_page: 25, last_page: 1, has_more: false } },
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
});
