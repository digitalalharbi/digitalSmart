import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ApiError } from '@/shared/api/client';
import { renderWithProviders } from '@/test/render';
import { StoresPanel } from './StoresPanel';
import type { ConnectableStore, Store, StoreReadResult } from './types';

const state = vi.hoisted(() => ({
  forbidden: false,
  stores: [] as Store[],
  connectable: [] as ConnectableStore[],
  read: null as StoreReadResult | null,
}));

const connected = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('./api', () => ({
  fetchStores: () =>
    state.forbidden
      ? Promise.reject(new ApiError('Forbidden.', 403))
      : Promise.resolve(state.stores),
  fetchConnectableStores: () => Promise.resolve(state.connectable),
  connectStore: (draft: unknown) => {
    connected(draft);

    return Promise.resolve(state.stores[0]);
  },
  readStore: () => Promise.resolve(state.read),
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

function store(overrides: Partial<Store> = {}): Store {
  return {
    id: 'store-1',
    client_id: 'client-1',
    client_name: 'Noon Retail',
    provider: 'salla',
    provider_label: 'Salla',
    external_id: '1183363243',
    name: 'SANDBOX — Noon Retail',
    domain: 'noonretail.sa',
    currency: 'SAR',
    timezone: 'Asia/Riyadh',
    is_active: true,
    last_synced_at: null,
    orders_synced_through: null,
    is_readable: true,
    ...overrides,
  };
}

function connectable(overrides: Partial<ConnectableStore> = {}): ConnectableStore {
  return {
    provider: 'salla',
    provider_label: 'Salla',
    external_id: '1183363243',
    name: 'SANDBOX — Noon Retail',
    currency: 'SAR',
    domain: 'noonretail.sa',
    timezone: 'Asia/Riyadh',
    is_registered: false,
    registered_to_client_name: null,
    ...overrides,
  };
}

const CLIENTS = [
  { id: 'client-1', name: 'Noon Retail' },
  { id: 'client-2', name: 'Rawdah Home' },
] as never;

beforeEach(() => {
  state.stores = [];
  state.connectable = [connectable()];
  state.read = {
    status: 'succeeded',
    orders_written: 12,
    products_written: 4,
    customers_written: 7,
    api_calls: 6,
    source: 'sandbox',
    failure_message: null,
  };
  connected.mockReset();
  state.forbidden = false;
  permissions.current = ['workspaces.update'];
});

/** A reader without `analytics.view`: the stores read is refused, like the figures beside it. */
function forbidStores(): void {
  state.forbidden = true;
}

describe('the stores panel', () => {
  /*
   * The whole point of the slice. Until this panel existed nothing could connect a store, so a workspace that
   * connected Salla had a connection and no store — every webhook delivery refused, every figure empty, and
   * nothing on any screen to say why.
   */
  it('says what an empty list means for the figures below it', async () => {
    await renderWithProviders(<StoresPanel clientId={null} clients={CLIENTS} />);

    expect(await screen.findByText('No store is connected')).toBeInTheDocument();
    expect(screen.getByText(/orders arriving by webhook are refused/)).toBeInTheDocument();
  });

  it('connects a merchant to a client, sending only what the platform cannot tell us', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<StoresPanel clientId={null} clients={CLIENTS} />);

    await user.click(await screen.findByRole('button', { name: 'Connect a store' }));

    /* The currency comes from the platform and is shown, never typed. */
    expect(await screen.findByText('SAR')).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText(/Client/), 'client-2');
    await user.click(screen.getByRole('button', { name: 'Connect it' }));

    await waitFor(() =>
      expect(connected).toHaveBeenCalledWith({
        provider: 'salla',
        external_id: '1183363243',
        client_id: 'client-2',
      }),
    );
  });

  /*
   * Shown rather than filtered out, with the client that has it. A merchant missing from this list reads as
   * "the connection cannot see it", and somebody then goes looking at the platform for a problem that is not
   * there.
   */
  it('shows a merchant another client already reads, and will not offer it again', async () => {
    state.connectable = [
      connectable({ is_registered: true, registered_to_client_name: 'Noon Retail' }),
    ];
    const user = userEvent.setup();
    await renderWithProviders(<StoresPanel clientId={null} clients={CLIENTS} />);

    await user.click(await screen.findByRole('button', { name: 'Connect a store' }));

    expect(await screen.findByText('Already read for Noon Retail')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Connect it' })).not.toBeInTheDocument();
  });

  it('points at the integrations page when no commerce platform is connected', async () => {
    state.connectable = [];
    const user = userEvent.setup();
    await renderWithProviders(<StoresPanel clientId={null} clients={CLIENTS} />);

    await user.click(await screen.findByRole('button', { name: 'Connect a store' }));

    expect(await screen.findByText(/No commerce platform is connected/)).toBeInTheDocument();
  });

  /* A store registered a second ago has not been read, and the row says that rather than showing a date. */
  it('says a store has never been read', async () => {
    state.stores = [store()];
    await renderWithProviders(<StoresPanel clientId="client-1" clients={CLIENTS} />);

    const list = await screen.findByRole('list', { name: 'Connected stores' });
    expect(within(list).getByText(/never read/)).toBeInTheDocument();
  });

  /*
   * Asked and answered are different facts. A read that reached the platform and brought nothing back leaves
   * the watermark alone, and "last read an hour ago" without that is how a failing store looks fine.
   */
  it('distinguishes a read that brought orders back from one that did not', async () => {
    state.stores = [store({ last_synced_at: '2026-10-08T09:00:00+00:00' })];
    await renderWithProviders(<StoresPanel clientId="client-1" clients={CLIENTS} />);

    expect(await screen.findByText(/no orders came back/)).toBeInTheDocument();
  });

  it('reports what a read brought back, and that it was sandbox', async () => {
    state.stores = [store()];
    const user = userEvent.setup();
    await renderWithProviders(<StoresPanel clientId="client-1" clients={CLIENTS} />);

    await user.click(await screen.findByRole('button', { name: 'Read now' }));

    expect(await screen.findByText(/12 orders, 4 products, 7 customers/)).toBeInTheDocument();
  });

  it('names what stopped a partial read rather than calling it done', async () => {
    state.stores = [store()];
    state.read = {
      status: 'partial',
      orders_written: 3,
      products_written: 0,
      customers_written: 0,
      api_calls: 2,
      source: 'sandbox',
      failure_message: 'Salla rate-limited the request.',
    };
    const user = userEvent.setup();
    await renderWithProviders(<StoresPanel clientId="client-1" clients={CLIENTS} />);

    await user.click(await screen.findByRole('button', { name: 'Read now' }));

    expect(await screen.findByText(/Salla rate-limited the request/)).toBeInTheDocument();
  });

  /* Its webhooks still arrive; nothing can go and ask it. The control says so rather than offering a no-op. */
  it('will not offer to read a store whose platform cannot be read', async () => {
    state.stores = [store({ is_readable: false })];
    await renderWithProviders(<StoresPanel clientId="client-1" clients={CLIENTS} />);

    const button = await screen.findByRole('button', { name: 'Cannot be read' });
    expect(button).toBeDisabled();
  });

  it('offers neither control to somebody who can only read the figures', async () => {
    permissions.current = [];
    state.stores = [store()];
    await renderWithProviders(<StoresPanel clientId="client-1" clients={CLIENTS} />);

    expect(await screen.findByText('SANDBOX — Noon Retail')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Connect a store' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Read now' })).not.toBeInTheDocument();
  });
});

/*
 * The page says once that a role cannot reach store revenue. This panel used to add a second alert about the
 * store list, so a reader refused access was told twice that something had gone wrong.
 */
describe('when the reader cannot read revenue at all', () => {
  it('says nothing, leaving the page its own sentence', async () => {
    forbidStores();
    await renderWithProviders(<StoresPanel clientId={null} clients={CLIENTS} />);

    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    expect(screen.queryByText('No store is connected')).not.toBeInTheDocument();
  });
});
