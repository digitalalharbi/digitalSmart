import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { PortalPage } from './PortalPage';
import type { PortalDetail, PortalSummary, PortalVocabulary } from './types';

const permissions = vi.hoisted(() => ({ current: ['portal.view', 'portal.manage'] as string[] }));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: {
    portalView: 'portal.view',
    portalManage: 'portal.manage',
    portalRead: 'portal.read',
  },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  vocabulary: null as PortalVocabulary | null,
  list: [] as PortalSummary[],
  detail: null as PortalDetail | null,
  savedModules: null as string[] | null,
  suspendedWith: null as string | null,
  enabled: null as string | null,
}));

vi.mock('./api', () => ({
  fetchPortalVocabulary: () => Promise.resolve(state.vocabulary),
  fetchPortals: () => Promise.resolve(state.list),
  fetchPortal: () => Promise.resolve(state.detail),
  enablePortal: (clientId: string) => {
    state.enabled = clientId;

    return Promise.resolve(state.detail);
  },
  suspendPortal: (input: { reason: string }) => {
    state.suspendedWith = input.reason;

    return Promise.resolve(state.detail);
  },
  resumePortal: () => Promise.resolve(state.detail),
  setPortalModules: (input: { modules: string[] }) => {
    state.savedModules = input.modules;

    return Promise.resolve(state.detail);
  },
}));

function vocabulary(): PortalVocabulary {
  return {
    modules: [
      {
        module: 'performance',
        label: 'Performance',
        description: 'What the advertising did.',
        prerequisite: 'a project bound to an advertising account',
      },
      {
        module: 'reports',
        label: 'Reports',
        description: 'The reports marked safe to send.',
        prerequisite: null,
      },
    ],
    always_available: [
      {
        module: 'requests',
        label: 'Requests',
        because: 'A client writes half of this thread.',
      },
    ],
    withheld: [
      {
        module: 'budgets',
        label: 'Budgets and pacing',
        because: "A budget carries the agency's own allocation thinking.",
      },
      {
        module: 'attribution',
        label: 'Attribution',
        because: 'Every attribution figure except the recorded one is a model.',
      },
    ],
  };
}

function summary(overrides: Partial<PortalSummary> = {}): PortalSummary {
  return {
    client_id: 'client-1',
    client_name: 'Alhambra',
    state: 'active',
    state_label: 'Active',
    exposed_count: 1,
    empty_count: 0,
    ...overrides,
  };
}

function detail(overrides: Partial<PortalDetail> = {}): PortalDetail {
  return {
    client_id: 'client-1',
    client_name: 'Alhambra',
    state: 'active',
    state_label: 'Active',
    enabled_at: '2026-10-01T09:00:00+00:00',
    enabled_by_name: 'Reem',
    suspended_at: null,
    suspended_by_name: null,
    suspended_reason: null,
    modules: [
      {
        module: 'performance',
        label: 'Performance',
        description: 'What the advertising did.',
        is_exposed: true,
        exposed_at: '2026-10-02T09:00:00+00:00',
        exposed_by_name: 'Reem',
        prerequisite: 'a project bound to an advertising account',
        is_prerequisite_met: true,
      },
      {
        module: 'reports',
        label: 'Reports',
        description: 'The reports marked safe to send.',
        is_exposed: false,
        exposed_at: null,
        exposed_by_name: null,
        prerequisite: null,
        is_prerequisite_met: true,
      },
    ],
    empty_count: 0,
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['portal.view', 'portal.manage'];
  state.vocabulary = vocabulary();
  state.list = [summary()];
  state.detail = detail();
  state.savedModules = null;
  state.suspendedWith = null;
  state.enabled = null;
});

describe('PortalPage', () => {
  it('lists every client, including the ones exposing nothing', async () => {
    state.list = [
      summary(),
      summary({
        client_id: 'client-2',
        client_name: 'Not set up yet',
        state: 'not_configured',
        state_label: 'Not set up',
        exposed_count: 0,
      }),
    ];

    await renderWithProviders(<PortalPage />);

    const clients = await screen.findByRole('region', { name: 'Clients' });

    /*
     * The question an agency asks before a quarterly review is which clients still cannot see anything, so the
     * list is driven by clients rather than by portals.
     */
    expect(within(clients).getByRole('button', { name: /Not set up yet/ })).toHaveTextContent(
      'Exposes nothing',
    );
  });

  it('leads with how many exposed surfaces would show a client nothing', async () => {
    state.list = [summary({ exposed_count: 2, empty_count: 1 })];
    state.detail = detail({
      empty_count: 1,
      modules: [
        {
          module: 'performance',
          label: 'Performance',
          description: 'What the advertising did.',
          is_exposed: true,
          exposed_at: '2026-10-02T09:00:00+00:00',
          exposed_by_name: 'Reem',
          prerequisite: 'a project bound to an advertising account',
          is_prerequisite_met: false,
        },
      ],
    });

    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));

    /* An empty surface exposed to a client reads as the agency having done nothing. */
    expect(
      await screen.findByText(/1 exposed surface would show this client nothing/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/the workspace has no a project bound to an advertising account/),
    ).toBeInTheDocument();
  });

  it('records who exposed each surface and when', async () => {
    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));

    expect(await screen.findByText(/Exposed .* by Reem/)).toBeInTheDocument();
    expect(screen.getByText(/Switched on .* by Reem\./)).toBeInTheDocument();
  });

  it('submits the whole set rather than one change', async () => {
    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));

    const surfaces = await screen.findByRole('group', { name: 'Surfaces' });
    await userEvent.click(within(surfaces).getByRole('button', { name: 'Reports' }));
    await userEvent.click(screen.getByRole('button', { name: 'Save what they can read' }));

    /*
     * The question being answered is "what can this client see", and a request carrying one change at a time can
     * leave that answer half-applied.
     */
    expect(state.savedModules).toEqual(['performance', 'reports']);
  });

  it('will not save an unchanged set', async () => {
    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));

    // Saving a form unchanged is not a disclosure, and a trail that logged it would bury the real entries.
    expect(await screen.findByRole('button', { name: 'Save what they can read' })).toBeDisabled();
  });

  it('needs a reason to switch a portal off', async () => {
    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'Switch it off' }));

    const confirm = screen.getByRole('button', { name: 'Switch it off' });
    expect(confirm).toBeDisabled();

    await userEvent.type(
      screen.getByLabelText(/Why it is being switched off/),
      'Contract under review.',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Switch it off' }));

    expect(state.suspendedWith).toBe('Contract under review.');
  });

  it('says the suspension reason stays inside the workspace', async () => {
    state.detail = detail({
      state: 'suspended',
      state_label: 'Suspended',
      suspended_at: '2026-10-05T09:00:00+00:00',
      suspended_by_name: 'Reem',
      suspended_reason: 'Payment overdue since September.',
    });

    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));

    expect(await screen.findByText(/Payment overdue since September/)).toBeInTheDocument();
    /*
     * Said on the agency's own screen. A client reading a commercial position on their portal page is that
     * conversation happening in the worst possible place.
     */
    expect(screen.getByText(/This reason stays inside the workspace/)).toBeInTheDocument();
  });

  it('publishes what will never be exposed, with the reason', async () => {
    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));

    const withheld = await screen.findByRole('region', { name: 'Never exposed' });

    /*
     * Shown rather than hidden. A screen listing only what can be exposed makes every refusal look like a feature
     * nobody got round to, and that question then gets asked once a quarter forever.
     */
    expect(within(withheld).getByText(/allocation thinking/)).toBeInTheDocument();
    expect(within(withheld).getByText(/except the recorded one is a model/)).toBeInTheDocument();

    const always = screen.getByRole('region', { name: 'Always available' });
    expect(within(always).getByText(/writes half of this thread/)).toBeInTheDocument();
  });

  it('lets a reader without the manage permission look and not change', async () => {
    permissions.current = ['portal.view'];

    await renderWithProviders(<PortalPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Alhambra/ }));

    await screen.findByRole('region', { name: 'Alhambra' });

    expect(
      screen.queryByRole('button', { name: 'Save what they can read' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Switch it off' })).not.toBeInTheDocument();
  });

  it('refuses the surface to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<PortalPage />);

    expect(
      await screen.findByText('The client portal is not part of your access'),
    ).toBeInTheDocument();
  });
});
