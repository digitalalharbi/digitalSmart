import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { IntegrationsPage } from './IntegrationsPage';
import type { ExternalAccount, ProjectBinding, ProviderRow } from './types';

const providersQuery = vi.hoisted(() => vi.fn());
const accountsQuery = vi.hoisted(() => vi.fn());
const bindingsQuery = vi.hoisted(() => vi.fn());
const bind = vi.hoisted(() => vi.fn());
const backfill = vi.hoisted(() => vi.fn());
const disconnect = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('./api', () => ({
  useProvidersQuery: () => providersQuery(),
  useExternalAccountsQuery: () => accountsQuery(),
  useProjectBindingsQuery: () => bindingsQuery(),
  useBindAccountMutation: () => ({ mutate: bind, isPending: false, error: null }),
  useUnbindAccountMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
  useSetBindingSyncMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
  useBackfillMutation: () => ({ mutate: backfill, isPending: false, error: null, data: undefined }),
  BACKFILL_WINDOWS: [30, 90, 180, 365],
  useConfigureConnectionMutation: () => ({
    mutate: vi.fn(),
    isPending: false,
    error: null,
    reset: vi.fn(),
  }),
  useDiscoverAccountsMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
  useDisconnectProviderMutation: () => ({
    mutate: disconnect,
    isPending: false,
    error: null,
    reset: vi.fn(),
  }),
}));

vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({
    data: {
      data: [{ id: 'p1', name: 'Ramadan Campaign', client: { id: 'c1', name: 'Alhambra Retail' } }],
    },
    isPending: false,
    isFetching: false,
  }),
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

function provider(overrides: Partial<ProviderRow> = {}): ProviderRow {
  return {
    provider: 'meta',
    label: 'Meta Ads',
    category: 'advertising',
    category_label: 'Advertising',
    api_status: 'requires_approval',
    api_status_label: 'Requires provider approval',
    auth_method: 'oauth2',
    entities: [{ slug: 'campaign', label: 'Campaigns' }],
    rate_limit: null,
    documentation_url: 'https://developers.facebook.com/docs/marketing-apis',
    capabilities: [
      { slug: 'metrics_sync', label: 'Sync performance metrics', is_write: false },
      { slug: 'budget_mutation', label: 'Change budgets', is_write: true },
    ],
    connection_id: null,
    status: 'not_configured',
    status_label: 'Not configured',
    can_sync: false,
    needs_attention: false,
    status_message: null,
    missing_credentials: ['client_id', 'client_secret'],
    is_sandbox: false,
    blocked_external: true,
    account_count: 0,
    last_checked_at: null,
    expires_at: null,
    ...overrides,
  };
}

function account(overrides: Partial<ExternalAccount> = {}): ExternalAccount {
  return {
    id: 'a1',
    provider: 'meta',
    provider_label: 'Meta Ads',
    external_id: 'act_sandbox_1001',
    name: 'SANDBOX — Alhambra Retail (Meta)',
    currency: 'SAR',
    timezone: 'Asia/Riyadh',
    status: 'active',
    is_bindable: true,
    binding_count: 0,
    discovered_at: null,
    ...overrides,
  };
}

const ok = <T,>(data: T) => ({ data, isPending: false, isError: false, error: null });

describe('IntegrationsPage', () => {
  beforeEach(() => {
    providersQuery.mockReturnValue(ok([provider()]));
    accountsQuery.mockReturnValue(ok([account()]));
    bindingsQuery.mockReturnValue(ok([] as ProjectBinding[]));
    bind.mockReset();
    disconnect.mockReset();
    permissions.current = ['workspaces.view', 'workspaces.update'];
  });

  it('explains that an unconnected provider is waiting on the provider, not on us', async () => {
    await renderWithProviders(<IntegrationsPage />);

    // A count, not a paragraph: how many wait. The reason is one hover away and always in the page.
    const strip = screen.getByRole('list', { name: 'Platform status' });
    expect(within(strip).getByText('Awaiting access')).toBeInTheDocument();
    expect(screen.getByText(/account-owner action, not a setting here/)).toBeInTheDocument();
  });

  /*
   * "Connected" read identically for a connection holding this repository's fixtures and one holding a
   * client's keys. Every figure already carries `SANDBOX`, so no report was dishonest — but this is the page
   * where somebody decides a provider is ready, and it said nothing.
   */
  it('says when a connection is holding sandbox credentials', async () => {
    providersQuery.mockReturnValue(
      ok([provider({ status: 'connected', status_label: 'Connected', is_sandbox: true })]),
    );
    await renderWithProviders(<IntegrationsPage />);

    expect(await screen.findByText('Sandbox credentials')).toBeInTheDocument();
    /* Beside the status, not instead of it: the connection is real, the account behind it is not. */
    expect(screen.getAllByText('Connected').length).toBeGreaterThan(0);
  });

  it('says nothing of the sort when the credentials are real', async () => {
    providersQuery.mockReturnValue(
      ok([provider({ status: 'connected', status_label: 'Connected', is_sandbox: false })]),
    );
    await renderWithProviders(<IntegrationsPage />);

    expect((await screen.findAllByText('Connected')).length).toBeGreaterThan(0);
    expect(screen.queryByText('Sandbox credentials')).not.toBeInTheDocument();
  });

  it('names the exact credentials a provider still needs', async () => {
    await renderWithProviders(<IntegrationsPage />);

    expect(screen.getByText(/client_id, client_secret/)).toBeInTheDocument();
  });

  it('distinguishes write capabilities from read ones', async () => {
    await renderWithProviders(<IntegrationsPage />);

    // A capability that changes live spend must be visibly different.
    expect(screen.getByText('Change budgets')).toBeInTheDocument();
    expect(screen.getByText('Sync performance metrics')).toBeInTheDocument();
  });

  it('says plainly when a platform has no published API, instead of showing an empty list', async () => {
    providersQuery.mockReturnValue(
      ok([
        provider({
          provider: 'openai',
          label: 'ChatGPT Ads',
          capabilities: [],
          auth_method: 'unknown',
        }),
      ]),
    );
    await renderWithProviders(<IntegrationsPage />);

    expect(screen.getByText(/No published API yet/)).toBeInTheDocument();
  });

  it('does not offer discovery for a provider with no credentials', async () => {
    await renderWithProviders(<IntegrationsPage />);

    expect(screen.queryByRole('button', { name: 'Find accounts' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add credentials' })).toBeInTheDocument();
  });

  it('offers discovery once credentials are stored', async () => {
    providersQuery.mockReturnValue(
      ok([
        provider({
          missing_credentials: [],
          status: 'configured',
          status_label: 'Awaiting authorisation',
        }),
      ]),
    );
    await renderWithProviders(<IntegrationsPage />);

    expect(screen.getByRole('button', { name: 'Find accounts' })).toBeInTheDocument();
  });

  it('asks for a project before showing anything bindable', async () => {
    await renderWithProviders(<IntegrationsPage />);

    expect(screen.getByText('Choose a project')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Grant access' })).not.toBeInTheDocument();
  });

  it('grants a project access to an account', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.type(screen.getByRole('combobox', { name: 'Project' }), 'Ra');
    await user.click(await screen.findByRole('option', { name: /Ramadan Campaign/ }));
    await user.click(screen.getByRole('button', { name: 'Grant access' }));

    expect(bind).toHaveBeenCalledWith({ projectId: 'p1', accountId: 'a1' });
  });

  it('will not offer to bind an account the provider has disabled', async () => {
    accountsQuery.mockReturnValue(ok([account({ status: 'disabled', is_bindable: false })]));
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.type(screen.getByRole('combobox', { name: 'Project' }), 'Ra');
    await user.click(await screen.findByRole('option', { name: /Ramadan Campaign/ }));

    expect(screen.getByRole('button', { name: 'Grant access' })).toBeDisabled();
    expect(screen.getByText('Disabled')).toBeInTheDocument();
  });

  it('shows granted access and offers revocation instead of granting', async () => {
    bindingsQuery.mockReturnValue(
      ok([
        {
          id: 'b1',
          project_id: 'p1',
          external_account: {
            id: 'a1',
            name: 'SANDBOX — Alhambra Retail (Meta)',
            provider: 'meta',
            provider_label: 'Meta Ads',
          },
          sync_enabled: true,
          bound_at: null,
          last_synced_at: null,
        },
      ] as ProjectBinding[]),
    );
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.type(screen.getByRole('combobox', { name: 'Project' }), 'Ra');
    await user.click(await screen.findByRole('option', { name: /Ramadan Campaign/ }));

    const row = within(screen.getByRole('list', { name: 'Discovered ad accounts' })).getByRole(
      'listitem',
    );
    expect(within(row).getByText('Access granted')).toBeInTheDocument();
    expect(within(row).getByRole('button', { name: 'Revoke' })).toBeInTheDocument();
    expect(within(row).getByRole('button', { name: 'Pause sync' })).toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Grant access' })).not.toBeInTheDocument();
  });

  it('hides granting from a role that cannot administer the workspace', async () => {
    permissions.current = ['workspaces.view'];
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.type(screen.getByRole('combobox', { name: 'Project' }), 'Ra');
    await user.click(await screen.findByRole('option', { name: /Ramadan Campaign/ }));

    expect(screen.queryByRole('button', { name: 'Grant access' })).not.toBeInTheDocument();
  });

  it('warns never to enter a client password when collecting credentials', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.click(screen.getByRole('button', { name: 'Add credentials' }));

    expect(await screen.findByText(/Never enter a client's platform password/)).toBeInTheDocument();
  });

  it('offers credential fields as password inputs that start empty', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.click(screen.getByRole('button', { name: 'Add credentials' }));

    const secret = await screen.findByLabelText(/Client secret/);
    expect(secret).toHaveAttribute('type', 'password');
    expect(secret).toHaveValue('');
  });

  it('reads an account’s history for the window chosen from a short list', async () => {
    bindingsQuery.mockReturnValue(
      ok([
        {
          id: 'b1',
          project_id: 'p1',
          external_account: {
            id: 'a1',
            name: 'SANDBOX — Alhambra Retail (Meta)',
            provider: 'meta',
            provider_label: 'Meta Ads',
          },
          sync_enabled: true,
          bound_at: null,
          last_synced_at: null,
        },
      ] as ProjectBinding[]),
    );
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.type(screen.getByRole('combobox', { name: 'Project' }), 'Ra');
    await user.click(await screen.findByRole('option', { name: /Ramadan Campaign/ }));
    await user.selectOptions(
      await screen.findByRole('combobox', { name: /History to read for/ }),
      '90',
    );
    await user.click(screen.getByRole('button', { name: 'Read history' }));

    expect(backfill).toHaveBeenCalledWith({ accountId: 'a1', days: 90 });
  });

  it('asks before disconnecting, says what it leaves alone, and disconnects only on confirmation', async () => {
    providersQuery.mockReturnValue(
      ok([
        provider({
          missing_credentials: [],
          status: 'connected',
          status_label: 'Connected',
          connection_id: 'k1',
        }),
      ]),
    );
    const user = userEvent.setup();
    await renderWithProviders(<IntegrationsPage />);

    await user.click(screen.getByRole('button', { name: 'Disconnect Meta Ads' }));

    const dialog = await screen.findByRole('dialog', { name: 'Disconnect Meta Ads?' });
    expect(disconnect).not.toHaveBeenCalled();
    expect(within(dialog).getByText(/credentials stored here are destroyed/i)).toBeInTheDocument();
    expect(within(dialog).getByText(/already read stays as it was/i)).toBeInTheDocument();
    expect(within(dialog).getByText(/not revoked from here/i)).toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(disconnect).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Disconnect Meta Ads' }));
    await user.click(
      within(await screen.findByRole('dialog', { name: 'Disconnect Meta Ads?' })).getByRole(
        'button',
        { name: 'Disconnect' },
      ),
    );

    expect(disconnect).toHaveBeenCalledTimes(1);
    expect(disconnect.mock.calls[0]?.[0]).toBe('meta');
  });
});
