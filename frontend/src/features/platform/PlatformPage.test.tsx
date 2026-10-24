import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { PlatformPage } from './PlatformPage';

const state = vi.hoisted(() => ({
  operator: true,
  posts: [] as Array<{ url: string; body: unknown }>,
}));

vi.mock('@/features/auth', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/auth');

  return {
    ...actual,
    useSession: () => ({
      user: {
        id: 'ops',
        name: 'Ops',
        email: 'ops@smartcode.test',
        workspaces: [],
        active_workspace: null,
        permissions: [],
        must_choose_password: false,
        is_platform_operator: state.operator,
      },
      can: () => false,
    }),
  };
});

const ROW = {
  id: 'w1',
  name: 'Tenant One',
  slug: 'tenant-one',
  type: 'agency',
  created_at: '2026-10-01T00:00:00+00:00',
  plan: 'agency',
  status: 'suspended',
  trial_ends_at: null,
  grace_ends_at: null,
  status_reason: 'Invoice unpaid',
  owners: 1,
  seats: { internal: 4, client: 2 },
  clients: 12,
  projects: 30,
  connections: { connected: 3 },
  overrides: 0,
  provisioning: 'complete',
  missing: [],
};

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      get: (url: string) =>
        Promise.resolve({
          data: url.endsWith('/w1')
            ? {
                data: {
                  ...ROW,
                  plan_detail: {
                    name: 'Agency',
                    features: [
                      { key: 'automation', enabled: true, source: 'plan', everywhere: false },
                    ],
                    limits: [
                      {
                        key: 'clients',
                        allowed: null,
                        used: 12,
                        source: 'plan',
                        monthly: false,
                        resets_at: null,
                        over: false,
                        refuses: true,
                      },
                    ],
                    overrides: [],
                  },
                  history: [
                    {
                      action: 'workspace.subscription_changed',
                      summary: 'ops moved the subscription from active to suspended.',
                      at: '2026-10-20T10:00:00+00:00',
                    },
                  ],
                },
              }
            : { data: [ROW], meta: { total: 1 } },
        }),
      post: (url: string, body: unknown) => {
        state.posts.push({ url, body });
        return Promise.resolve({ data: null });
      },
    },
  };
});

describe('PlatformPage', () => {
  beforeEach(() => {
    state.operator = true;
    state.posts = [];
  });

  it('declines to draw itself for somebody who is not an operator', async () => {
    state.operator = false;
    await renderWithProviders(<PlatformPage />);

    expect(screen.getByText('This console is for platform operators.')).toBeInTheDocument();
  });

  it('lists tenants as counts and states, and changes a state only with a reason', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<PlatformPage />);

    const card = await screen.findByRole('article', { name: 'Tenant One' });
    expect(card).toHaveTextContent('Paused');
    expect(card).toHaveTextContent('12');

    await user.click(screen.getByRole('button', { name: 'Manage' }));
    expect(await screen.findByText('Reason on record: Invoice unpaid')).toBeInTheDocument();
    expect(screen.getByText(/moved the subscription from active to suspended/)).toBeInTheDocument();

    const change = screen.getByRole('button', { name: 'Change state' });
    expect(change).toBeDisabled();

    await user.selectOptions(screen.getByLabelText('State'), 'active');
    await user.type(screen.getAllByLabelText(/^Reason/)[0] as HTMLElement, 'Paid in full');
    await user.click(change);

    expect(state.posts).toContainEqual({
      url: '/platform/workspaces/w1/status',
      body: { status: 'active', reason: 'Paid in full' },
    });
  });
});
