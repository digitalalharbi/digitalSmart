import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { AdministrationPage } from './AdministrationPage';
import type { AuditEntry, Member } from './types';

const membersQuery = vi.hoisted(() => vi.fn());
const auditQuery = vi.hoisted(() => vi.fn());
const changeRole = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('./api', () => ({
  useMembersQuery: () => membersQuery(),
  useAuditLogQuery: () => auditQuery(),
  useChangeMemberRoleMutation: () => ({ mutate: changeRole, isPending: false, error: null }),
  useChangeMemberStatusMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
  useRemoveMemberMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
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
