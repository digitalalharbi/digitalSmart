import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { AdministrationPage } from './AdministrationPage';
import type { AuditEntry, Member } from './types';

const membersQuery = vi.hoisted(() => vi.fn());
const auditQuery = vi.hoisted(() => vi.fn());
const changeRole = vi.hoisted(() => vi.fn());
const heldWorkQuery = vi.hoisted(() => vi.fn());
const removeMember = vi.hoisted(() => vi.fn());
type RemoveState = { isPending: boolean; isSuccess: boolean; error: unknown; data: unknown };

const removeState = vi.hoisted<{ current: RemoveState }>(() => ({
  current: { isPending: false, isSuccess: false, error: null, data: undefined },
}));
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('./api', () => ({
  useMembersQuery: () => membersQuery(),
  useAuditLogQuery: () => auditQuery(),
  useChangeMemberRoleMutation: () => ({ mutate: changeRole, isPending: false, error: null }),
  useChangeMemberStatusMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
  useHeldWorkQuery: () => heldWorkQuery(),
  useRemoveMemberMutation: () => ({ mutate: removeMember, reset: vi.fn(), ...removeState.current }),
}));

vi.mock('@/features/auth', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/auth');
  return {
    ...actual,
    useSession: () => ({
      user: { id: 'me' },
      isLoading: false,
      isAuthenticated: true,
      can: (permission: string) => permissions.current.includes(permission),
    }),
  };
});

function member(overrides: Partial<Member> = {}): Member {
  return {
    user_id: 'u1',
    name: 'Sara Idris',
    email: 'sara@agency.com',
    status: 'active',
    role: 'media-buyer',
    role_name: 'Media buyer',
    job_title: null,
    is_active: true,
    last_login_at: null,
    joined_at: null,
    project_count: 2,
    ...overrides,
  };
}

function entry(overrides: Partial<AuditEntry> = {}): AuditEntry {
  return {
    id: 'a1',
    action: 'client.created',
    group: 'client',
    is_security_event: false,
    actor: { id: 'u1', name: 'Sara Idris' },
    auditable: { type: 'client', id: 'c1' },
    summary: 'Created client "Alhambra Retail".',
    changes: {},
    ip_address: '127.0.0.1',
    request_id: null,
    created_at: '2026-10-04T10:30:00+00:00',
    ...overrides,
  };
}

const page = <T,>(items: T[]) => ({
  data: {
    data: items,
    meta: { total: items.length, page: 1, per_page: 25, last_page: 1, has_more: false },
  },
  isPending: false,
  isError: false,
  error: null,
});

describe('AdministrationPage', () => {
  beforeEach(() => {
    membersQuery.mockReturnValue(page([member()]));
    auditQuery.mockReturnValue(page([entry()]));
    changeRole.mockReset();
    removeMember.mockReset();
    heldWorkQuery.mockReturnValue({
      data: { tasks: 3, leads: 1, client_requests: 0, creative_requests: 0, total: 4 },
      isPending: false,
      isError: false,
    });
    removeState.current = { isPending: false, isSuccess: false, error: null, data: undefined };
    permissions.current = ['users.view', 'audit.view'];
  });

  it('refuses the surface entirely to a role with neither permission', async () => {
    permissions.current = [];
    await renderWithProviders(<AdministrationPage />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Your role does not include workspace administration.',
    );
  });

  it('shows only the tabs the role may use', async () => {
    permissions.current = ['audit.view'];
    await renderWithProviders(<AdministrationPage />);

    expect(screen.queryByRole('tab', { name: 'People' })).not.toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Audit log' })).toBeInTheDocument();
  });

  it('lists people with their role and status', async () => {
    await renderWithProviders(<AdministrationPage />);

    const row = screen.getByRole('row', { name: /Sara Idris/ });
    expect(within(row).getByText('sara@agency.com')).toBeInTheDocument();
    expect(within(row).getByText('Active')).toBeInTheDocument();
  });

  it('renders the role as read-only text when the viewer cannot change it', async () => {
    await renderWithProviders(<AdministrationPage />);

    expect(screen.queryByLabelText('Role for Sara Idris')).not.toBeInTheDocument();
    expect(screen.getByText('Media buyer')).toBeInTheDocument();
  });

  it('changes a role through the server when permitted', async () => {
    permissions.current = ['users.view', 'users.update'];
    const user = userEvent.setup();
    await renderWithProviders(<AdministrationPage />);

    await user.selectOptions(screen.getByLabelText('Role for Sara Idris'), 'project-manager');

    expect(changeRole).toHaveBeenCalledWith({ userId: 'u1', role: 'project-manager' });
  });

  it('does not offer removal of yourself', async () => {
    permissions.current = ['users.view', 'workspaces.manage_members'];
    membersQuery.mockReturnValue(page([member({ user_id: 'me', name: 'Me' })]));
    await renderWithProviders(<AdministrationPage />);

    expect(screen.getByText('You')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
  });

  /*
   * Removal is irreversible and it releases everything still open in that person's name. The figure is what
   * makes "remove" over a loaded colleague a different decision from "remove" over an empty one, so it is on
   * screen before the confirming click rather than reported after it.
   */
  it('says what will be released before removing anybody', async () => {
    permissions.current = ['users.view', 'workspaces.manage_members'];
    const user = userEvent.setup();
    await renderWithProviders(<AdministrationPage />);

    await user.click(screen.getByRole('button', { name: 'Remove' }));

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveTextContent('Remove Sara Idris from this workspace?');
    expect(within(dialog).getByText('Tasks')).toBeInTheDocument();
    expect(within(dialog).getByText('3')).toBeInTheDocument();
    /* Kinds holding nothing are left out rather than listed as zeroes. */
    expect(within(dialog).queryByText('Client requests')).not.toBeInTheDocument();
    expect(removeMember).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole('button', { name: 'Remove them' }));

    expect(removeMember).toHaveBeenCalledWith('u1');
  });

  it('says plainly when it cannot read what they are holding', async () => {
    permissions.current = ['users.view', 'workspaces.manage_members'];
    heldWorkQuery.mockReturnValue({ data: undefined, isPending: false, isError: true });
    const user = userEvent.setup();
    await renderWithProviders(<AdministrationPage />);

    await user.click(screen.getByRole('button', { name: 'Remove' }));

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveTextContent('could not be read');
    /* Still offered: refusing the removal because a figure is unavailable would be the wrong trade. */
    expect(within(dialog).getByRole('button', { name: 'Remove them' })).toBeEnabled();
  });

  it('reports what the removal actually released', async () => {
    permissions.current = ['users.view', 'workspaces.manage_members'];
    removeState.current = {
      isPending: false,
      isSuccess: true,
      error: null,
      data: { tasks: 3, leads: 1, client_requests: 0, creative_requests: 2, total: 6 },
    };
    const user = userEvent.setup();
    await renderWithProviders(<AdministrationPage />);

    await user.click(screen.getByRole('button', { name: 'Remove' }));

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveTextContent('Released from their name and now unassigned:');
    expect(within(dialog).getByText('Creative briefs')).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'Remove them' })).not.toBeInTheDocument();
  });

  it('shows the audit trail with its actor and marks security events', async () => {
    auditQuery.mockReturnValue(
      page([entry({ action: 'user.logged_in', is_security_event: true, summary: 'Signed in.' })]),
    );
    const user = userEvent.setup();
    await renderWithProviders(<AdministrationPage />);

    await user.click(screen.getByRole('tab', { name: 'Audit log' }));

    expect(screen.getByText('Signed in.')).toBeInTheDocument();
    expect(screen.getByText('Security')).toBeInTheDocument();
    expect(screen.getByText('Sara Idris')).toBeInTheDocument();
  });

  it('explains an empty audit trail', async () => {
    auditQuery.mockReturnValue(page([]));
    const user = userEvent.setup();
    await renderWithProviders(<AdministrationPage />);

    await user.click(screen.getByRole('tab', { name: 'Audit log' }));

    expect(screen.getByText('Nothing recorded yet')).toBeInTheDocument();
  });
});
