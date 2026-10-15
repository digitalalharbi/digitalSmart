import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ApiError } from '@/shared/api/client';
import { ClientsPage } from './ClientsPage';
import type { Client } from './types';

const clientsQuery = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('./api', () => ({
  useClientsQuery: () => clientsQuery(),
  useCreateClientMutation: () => ({
    mutateAsync: vi.fn(),
    isPending: false,
    error: null,
    reset: vi.fn(),
  }),
  useUpdateClientMutation: () => ({
    mutateAsync: vi.fn(),
    isPending: false,
    error: null,
    reset: vi.fn(),
  }),
  useChangeClientStatusMutation: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteClientMutation: () => ({ mutate: vi.fn(), isPending: false }),
  /* The offboarding dialog's hooks. Never reached unless somebody chooses Offboarded. */
  useOffboardingPreview: () => ({ data: undefined, isPending: true }),
  useOffboardClientMutation: () => ({ mutate: vi.fn(), isPending: false, isError: false, error: null }),
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

function client(overrides: Partial<Client> = {}): Client {
  return {
    id: 'c1',
    name: 'Alhambra Retail',
    slug: 'alhambra-retail',
    status: 'active',
    legal_name: null,
    industry: 'Retail',
    website: null,
    primary_contact_name: null,
    primary_contact_email: 'ops@alhambra.sa',
    primary_contact_phone: null,
    default_currency: 'SAR',
    notes: null,
    is_demo: false,
    project_count: 3,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function page(items: Client[]) {
  return {
    data: {
      data: items,
      meta: { total: items.length, page: 1, per_page: 25, last_page: 1, has_more: false },
    },
    isPending: false,
    isError: false,
    error: null,
    isFetching: false,
  };
}

describe('ClientsPage', () => {
  beforeEach(() => {
    clientsQuery.mockReset();
    permissions.current = ['clients.view'];
  });

  /*
   * The page opens on cards: a client list is a list of relationships, and a five-column table reads like an
   * inventory. Both views carry the same facts, which is what these two tests are really asserting — the
   * status, the industry and the number of projects survive the choice of layout.
   */
  it('renders each client with its status and project count', async () => {
    clientsQuery.mockReturnValue(page([client()]));
    await renderWithProviders(<ClientsPage />);

    const card = screen.getByText('Alhambra Retail').closest('div[class*="rounded-2xl"]');
    expect(card).not.toBeNull();
    expect(within(card as HTMLElement).getByText('Active')).toBeInTheDocument();
    expect(within(card as HTMLElement).getByText('Retail')).toBeInTheDocument();
    expect(within(card as HTMLElement).getByText('3')).toBeInTheDocument();
  });

  it('shows the same facts in the table view', async () => {
    clientsQuery.mockReturnValue(page([client()]));
    const user = userEvent.setup();
    await renderWithProviders(<ClientsPage />);

    await user.click(screen.getByRole('button', { name: 'Table' }));

    const row = screen.getByRole('row', { name: /Alhambra Retail/ });
    expect(within(row).getByText('Active')).toBeInTheDocument();
    expect(within(row).getByText('Retail')).toBeInTheDocument();
    expect(within(row).getByText('3')).toBeInTheDocument();
  });

  it('labels demo records so they are never mistaken for client data', async () => {
    clientsQuery.mockReturnValue(page([client({ is_demo: true })]));
    await renderWithProviders(<ClientsPage />);

    expect(screen.getByText('DEMO DATA')).toBeInTheDocument();
  });

  it('hides the create action from a role that cannot create', async () => {
    clientsQuery.mockReturnValue(page([client()]));
    await renderWithProviders(<ClientsPage />);

    expect(screen.queryByRole('button', { name: 'New client' })).not.toBeInTheDocument();
  });

  it('offers the create action when the role allows it', async () => {
    permissions.current = ['clients.view', 'clients.create'];
    clientsQuery.mockReturnValue(page([client()]));
    await renderWithProviders(<ClientsPage />);

    expect(screen.getByRole('button', { name: 'New client' })).toBeInTheDocument();
  });

  it('explains a 403 instead of showing a generic failure', async () => {
    clientsQuery.mockReturnValue({
      data: undefined,
      isPending: false,
      isError: true,
      error: new ApiError('Forbidden', 403),
      isFetching: false,
    });
    await renderWithProviders(<ClientsPage />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Your role does not include access to clients.',
    );
  });

  it('distinguishes an empty workspace from an empty filter result', async () => {
    clientsQuery.mockReturnValue(page([]));
    const user = userEvent.setup();
    const { rerender } = await renderWithProviders(<ClientsPage />);

    expect(screen.getByText('No clients yet')).toBeInTheDocument();

    await user.type(screen.getByLabelText('Search clients'), 'zzz');
    rerender(<ClientsPage />);

    expect(await screen.findByText('No clients match these filters')).toBeInTheDocument();
  });

  it('shows a busy state while the first page loads', async () => {
    clientsQuery.mockReturnValue({
      data: undefined,
      isPending: true,
      isError: false,
      error: null,
      isFetching: true,
    });
    await renderWithProviders(<ClientsPage />);

    /*
     * By role, not only by label. The label used to sit on a plain `<div>`, where `aria-label` is **prohibited**
     * — invalid ARIA that axe reports as serious, and which it only ever caught intermittently because a
     * loading state is on screen for a second. `role="status"` both permits the name and makes the region a
     * polite live region, so the announcement happens when the skeleton appears.
     */
    expect(screen.getByRole('status', { name: 'Loading clients…' })).toBeInTheDocument();
  });

  it('offers only the status transitions the domain permits', async () => {
    permissions.current = ['clients.view', 'clients.update'];
    clientsQuery.mockReturnValue(page([client({ status: 'active' })]));
    const user = userEvent.setup();
    await renderWithProviders(<ClientsPage />);

    await user.click(screen.getByRole('button', { name: /Actions for Alhambra Retail/ }));

    const menu = await screen.findByRole('menu');
    // active -> paused | archived. Never back to prospect.
    expect(within(menu).getByRole('menuitem', { name: 'Paused' })).toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: 'Archived' })).toBeInTheDocument();
    expect(within(menu).queryByRole('menuitem', { name: 'Prospect' })).not.toBeInTheDocument();
  });

  it('offers no transitions out of archived', async () => {
    permissions.current = ['clients.view', 'clients.update'];
    clientsQuery.mockReturnValue(page([client({ status: 'archived' })]));
    const user = userEvent.setup();
    await renderWithProviders(<ClientsPage />);

    await user.click(screen.getByRole('button', { name: /Actions for Alhambra Retail/ }));

    const menu = await screen.findByRole('menu');
    expect(within(menu).queryByText('Change status')).not.toBeInTheDocument();
  });

  it('renders no row menu at all for a read-only role', async () => {
    clientsQuery.mockReturnValue(page([client()]));
    await renderWithProviders(<ClientsPage />);

    expect(screen.queryByRole('button', { name: /Actions for/ })).not.toBeInTheDocument();
  });
});
