import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { AccountQueuePanel } from './AccountQueuePanel';
import { ConnectionsPanel } from './ConnectionsPanel';

const get = vi.hoisted(() => vi.fn());
const post = vi.hoisted(() => vi.fn());
const del = vi.hoisted(() => vi.fn());

vi.mock('@/shared/api/client', async () => ({
  ...(await vi.importActual<Record<string, unknown>>('@/shared/api/client')),
  api: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    delete: (...a: unknown[]) => del(...a),
    put: vi.fn(),
  },
  ensureCsrfCookie: () => Promise.resolve(),
}));
vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));
vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({
    data: { data: [{ id: 'p1', name: 'Ramadan', client: { name: 'Alhambra' } }] },
    isFetching: false,
  }),
}));
vi.mock('./api', () => ({ useProvidersQuery: () => ({ data: [] }) }));

const connections = [
  {
    id: 'k1',
    provider: 'meta',
    provider_label: 'Meta Ads',
    label: 'Meta Ads — Smart Code',
    owner: 'agency',
    owner_client: null,
    state: 'healthy',
    state_label: 'Healthy',
    needs_action: false,
    accounts: { discovered: 5, bound: 3, unassigned: 2 },
    last_checked_at: null,
    expires_at: null,
    advanced: null,
  },
  {
    id: 'k2',
    provider: 'meta',
    provider_label: 'Meta Ads',
    label: 'Meta Ads — Alhambra',
    owner: 'client',
    owner_client: { id: 'c1', name: 'Alhambra' },
    state: 'needs_reconnect',
    state_label: 'Needs reconnect',
    needs_action: true,
    accounts: { discovered: 1, bound: 1, unassigned: 0 },
    last_checked_at: null,
    expires_at: null,
    advanced: { message: 'Token expired.' },
  },
];

describe('ConnectionsPanel', () => {
  beforeEach(() => {
    get.mockReset();
    post.mockReset();
    del.mockReset();
    get.mockResolvedValue({ data: { data: connections } });
  });

  it('names each connection by what it is and whose it is, never only by provider', async () => {
    await renderWithProviders(<ConnectionsPanel />);

    const agency = await screen.findByRole('article', { name: 'Meta Ads — Smart Code' });
    expect(within(agency).getByText('Agency-owned')).toBeInTheDocument();

    const client = screen.getByRole('article', { name: 'Meta Ads — Alhambra' });
    expect(within(client).getByText('Granted by Alhambra')).toBeInTheDocument();
    expect(within(client).getByText('Needs reconnect')).toBeInTheDocument();
  });

  /* Technical detail sits under Advanced and exists only where the server sent it. */
  it('keeps the technical message behind Advanced, and shows none where there is none', async () => {
    await renderWithProviders(<ConnectionsPanel />);
    const agency = await screen.findByRole('article', { name: 'Meta Ads — Smart Code' });
    const client = screen.getByRole('article', { name: 'Meta Ads — Alhambra' });

    expect(within(agency).queryByText('Advanced')).not.toBeInTheDocument();
    expect(within(client).getByText('Advanced')).toBeInTheDocument();
  });

  it('discovers on the connection it was asked about, by id', async () => {
    const user = userEvent.setup();
    post.mockResolvedValue({ data: {} });
    await renderWithProviders(<ConnectionsPanel />);

    const client = await screen.findByRole('article', { name: 'Meta Ads — Alhambra' });
    await user.click(within(client).getByRole('button', { name: 'Discover accounts' }));

    expect(post).toHaveBeenCalledWith('/integrations/connections/k2/discover');
  });
});

describe('AccountQueuePanel', () => {
  beforeEach(() => {
    get.mockReset();
    post.mockReset();
    del.mockReset();
    get.mockResolvedValue({
      data: {
        data: [
          {
            id: 'a1',
            name: 'KSA Main',
            provider: 'meta',
            external_id: 'act_1',
            connection: { id: 'k1', label: 'Meta Ads — Smart Code' },
            owner_client: null,
            currency: 'SAR',
            status: 'active',
            state: 'unassigned',
            projects: [],
            campaigns: 4,
          },
        ],
        meta: { counts: { unassigned: 1, bound: 3, shared: 0, ignored: 2 } },
      },
    });
  });

  it('counts the whole population per state and leads with the human label', async () => {
    await renderWithProviders(<AccountQueuePanel />);

    const row = await screen.findByRole('article', { name: 'KSA Main' });
    expect(within(row).getByText(/act_1/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Ignored\s*2/ })).toBeInTheDocument();
  });

  it('ignores an unassigned account without deleting it', async () => {
    const user = userEvent.setup();
    post.mockResolvedValue({ data: {} });
    await renderWithProviders(<AccountQueuePanel />);

    await user.click(await screen.findByRole('button', { name: 'Ignore' }));

    expect(post).toHaveBeenCalledWith('/integrations/accounts/a1/ignore');
    expect(del).not.toHaveBeenCalled();
  });
});
