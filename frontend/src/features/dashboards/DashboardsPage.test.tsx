import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ApiError } from '@/shared/api/client';
import { DashboardsPage } from './DashboardsPage';
import type { DashboardPayload, WidgetPayload } from './types';
import type { CataloguePayload } from '@/shared/metrics';

/**
 * What the acting role may do.
 *
 * Mutable so one test can take a permission away: the builder offers different
 * controls to a reader who may author a dashboard and one who may only read it, and
 * both paths matter.
 */
const permissions = vi.hoisted(() => ({
  current: ['analytics.view', 'saved_views.manage'] as string[],
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

const fetchDashboards = vi.hoisted(() => vi.fn());
const fetchDashboard = vi.hoisted(() => vi.fn());
const createDashboard = vi.hoisted(() => vi.fn());
const updateDashboard = vi.hoisted(() => vi.fn());
const deleteDashboard = vi.hoisted(() => vi.fn());
const duplicateDashboard = vi.hoisted(() => vi.fn());

vi.mock('./api', () => ({
  fetchDashboards: () => fetchDashboards(),
  fetchDashboard: (id: string) => fetchDashboard(id),
  createDashboard: (draft: unknown) => createDashboard(draft),
  updateDashboard: (id: string, draft: unknown) => updateDashboard(id, draft),
  deleteDashboard: (id: string) => deleteDashboard(id),
  duplicateDashboard: (id: string, name?: string) => duplicateDashboard(id, name),
}));

const catalogue: CataloguePayload = {
  metrics: ['spend', 'roas', 'clicks', 'conversions'].map((value) => ({
    value,
    label: value.toUpperCase(),
    format: 'count',
    precision: 0,
    is_derived: false,
    is_monetary: false,
    additive_across_entities: true,
    lower_is_better: false,
    depends_on: [],
  })),
  dimensions: [],
  providers: [{ value: 'meta', label: 'Meta Ads' }],
  sources: [],
};

/*
 * The widgets fetch their own analytics, which is not what these tests are about —
 * they assert the builder's behaviour: what it sends, what it offers, what it
 * refuses. The widget body is replaced so a failed fetch cannot mask that.
 */
vi.mock('./DashboardWidget', () => ({
  DashboardWidget: ({ widget }: { widget: { type: string } }) => (
    <div data-testid={`widget-${widget.type}`} />
  ),
}));

vi.mock('@/features/analytics', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/analytics');
  const { useQuery } = await import('@tanstack/react-query');

  return {
    ...actual,
    useMetricCatalogue: () => {
      const result = useQuery<CataloguePayload>({
        queryKey: ['catalogue'],
        queryFn: () => Promise.resolve(catalogue),
      });

      return {
        ...result,
        definitions: new Map((result.data?.metrics ?? []).map((metric) => [metric.value, metric])),
      };
    },
  };
});

function widget(overrides: Partial<WidgetPayload> = {}): WidgetPayload {
  return {
    id: 'w1',
    title: 'Spend by campaign',
    type: 'bar',
    type_label: 'Comparison',
    metrics: ['spend'],
    dimension: 'campaign',
    filter_overrides: null,
    column: 0,
    row: 0,
    width: 6,
    height: 2,
    options: {},
    ...overrides,
  };
}

function dashboard(overrides: Partial<DashboardPayload> = {}): DashboardPayload {
  return {
    id: 'd1',
    name: 'Weekly performance',
    description: null,
    visibility: 'private',
    visibility_label: 'Only me',
    filters: { preset: '28d', compare: 'previous_period' },
    is_default: true,
    project_id: null,
    project_name: null,
    author_name: 'Demo Owner',
    can_manage: true,
    grid_columns: 12,
    widgets: [widget()],
    updated_at: '2026-10-05T00:00:00+00:00',
    ...overrides,
  };
}

describe('DashboardsPage', () => {
  beforeEach(() => {
    for (const mock of [
      fetchDashboards,
      fetchDashboard,
      createDashboard,
      updateDashboard,
      deleteDashboard,
      duplicateDashboard,
    ]) {
      mock.mockReset();
    }

    permissions.current = ['analytics.view', 'saved_views.manage'];
    fetchDashboards.mockResolvedValue([dashboard()]);
    fetchDashboard.mockResolvedValue(dashboard());
    // A save returns the stored dashboard, as the API does.
    createDashboard.mockResolvedValue(dashboard({ id: 'new' }));
    updateDashboard.mockResolvedValue(dashboard());
    duplicateDashboard.mockResolvedValue(dashboard({ id: 'copy' }));
  });

  it('lands on the reader’s default dashboard', async () => {
    fetchDashboards.mockResolvedValue([
      dashboard({ id: 'd1', name: 'Not default', is_default: false }),
      dashboard({ id: 'd2', name: 'My default', is_default: true }),
    ]);
    fetchDashboard.mockResolvedValue(dashboard({ id: 'd2', name: 'My default' }));

    await renderWithProviders(<DashboardsPage />);

    // Opening a dashboard picker and choosing every time would be busywork.
    await waitFor(() => expect(fetchDashboard).toHaveBeenCalledWith('d2'));
  });

  it('renders the saved widgets without edit controls until asked', async () => {
    await renderWithProviders(<DashboardsPage />);

    expect(await screen.findByTestId('widget-bar')).toBeInTheDocument();

    // Reading is the default state; editing is deliberate.
    expect(screen.queryByRole('button', { name: /Remove this widget/i })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Edit$/ })).toBeInTheDocument();
  });

  it('offers a starting canvas rather than an empty one for a new dashboard', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));

    // An empty canvas explains nothing about what a dashboard is for.
    expect(await screen.findByTestId('widget-line')).toBeInTheDocument();
    expect(screen.getAllByTestId('widget-scorecard')).toHaveLength(2);
  });

  it('sends the whole canvas on save, including placements', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));
    await userEvent.click(screen.getByRole('button', { name: /Save dashboard/i }));

    await waitFor(() => expect(createDashboard).toHaveBeenCalledOnce());

    const draft = createDashboard.mock.calls[0]?.[0] as {
      widgets: { type: string; column: number; row: number; width: number }[];
      filters: Record<string, unknown>;
    };

    /*
     * What the reader sees is what the dashboard is, so the whole set goes — and
     * every widget carries the placement the canvas re-flowed it to, not the one it
     * was created with.
     */
    expect(draft.widgets).toHaveLength(4);
    expect(draft.widgets.every((w) => w.column + w.width <= 12)).toBe(true);
    expect(draft.filters).toMatchObject({ preset: '28d' });
  });

  it('reorders a widget and keeps the layout valid', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));
    await userEvent.click((await screen.findAllByRole('button', { name: /Move later/i }))[0]!);
    await userEvent.click(screen.getByRole('button', { name: /Save dashboard/i }));

    await waitFor(() => expect(createDashboard).toHaveBeenCalled());

    const draft = createDashboard.mock.calls[0]?.[0] as {
      widgets: { column: number; row: number; width: number }[];
    };

    // Re-flowed after the move: no widget overlaps and none runs off the grid.
    for (const [index, first] of draft.widgets.entries()) {
      for (const second of draft.widgets.slice(index + 1)) {
        const sameRow = first.row === second.row;
        const overlap =
          first.column < second.column + second.width && second.column < first.column + first.width;

        expect(sameRow && overlap).toBe(false);
      }
    }
  });

  it('resizes a widget through its own controls', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));

    const wider = (await screen.findAllByRole('button', { name: /Make wider/i }))[0]!;
    await userEvent.click(wider);
    await userEvent.click(screen.getByRole('button', { name: /Save dashboard/i }));

    await waitFor(() => expect(createDashboard).toHaveBeenCalled());

    const draft = createDashboard.mock.calls[0]?.[0] as { widgets: { width: number }[] };

    // The first widget started at 3 columns.
    expect(draft.widgets[0]?.width).toBe(6);
  });

  it('removes a widget', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));

    const before = screen.getAllByRole('button', { name: /Remove this widget/i }).length;
    await userEvent.click(screen.getAllByRole('button', { name: /Remove this widget/i })[0]!);

    expect(screen.getAllByRole('button', { name: /Remove this widget/i })).toHaveLength(before - 1);
  });

  it('adds a widget through the editor', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));
    await userEvent.click(screen.getByRole('button', { name: /Add a widget/i }));

    // Defaults to a comparison grouped by campaign, which is the common case.
    await userEvent.click(screen.getByRole('button', { name: /^Add widget$/ }));

    expect(screen.getAllByTestId('widget-bar').length).toBeGreaterThan(0);
  });

  it('asks for a second metric when the reader picks a scatter', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));
    await userEvent.click(screen.getByRole('button', { name: /Add a widget/i }));
    await userEvent.click(screen.getByRole('button', { name: 'Efficiency vs scale' }));

    /*
     * The controls constrain each other, so an invalid combination is never offered.
     * Discovering the rule by being refused on save is a worse way to learn it.
     */
    expect(screen.getByText(/needs two/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Add widget$/ })).toBeDisabled();
  });

  it('drops the date grouping when a share of total is chosen', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));
    await userEvent.click(screen.getByRole('button', { name: /Add a widget/i }));
    await userEvent.click(screen.getByRole('button', { name: 'Share of total' }));

    // Ninety slices, one per day, summing to a number nobody asked for.
    const groupBy = screen.getByRole('group', { name: /Group by/i });
    expect(within(groupBy).queryByRole('button', { name: 'Date' })).not.toBeInTheDocument();
  });

  it('hides the grouping entirely for a single figure', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));
    await userEvent.click(screen.getByRole('button', { name: /Add a widget/i }));
    await userEvent.click(screen.getByRole('button', { name: 'Single figure' }));

    expect(screen.queryByRole('group', { name: /Group by/i })).not.toBeInTheDocument();
  });

  it('shows the server’s reason when a save is refused', async () => {
    createDashboard.mockRejectedValue(
      new ApiError('"Left" would sit on top of "Right". Move one of them.', 422),
    );

    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /New dashboard/i }));
    await userEvent.click(screen.getByRole('button', { name: /Save dashboard/i }));

    /*
     * The server catches layouts the editor cannot, and its message names what is
     * wrong and what would fix it — so it is shown rather than replaced by a generic
     * failure.
     */
    expect(await screen.findByText(/would sit on top of/i)).toBeInTheDocument();
  });

  it('discards an edit without touching the saved dashboard', async () => {
    await renderWithProviders(<DashboardsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /^Edit$/ }));
    await userEvent.click(screen.getAllByRole('button', { name: /Remove this widget/i })[0]!);
    await userEvent.click(screen.getByRole('button', { name: /Cancel/i }));

    // Nothing was sent, and the saved canvas is intact.
    expect(updateDashboard).not.toHaveBeenCalled();
    expect(await screen.findByTestId('widget-bar')).toBeInTheDocument();
  });

  it('offers duplicate but not edit on a colleague’s dashboard', async () => {
    const shared = dashboard({ can_manage: false, visibility: 'workspace' });
    fetchDashboards.mockResolvedValue([shared]);
    fetchDashboard.mockResolvedValue(shared);

    await renderWithProviders(<DashboardsPage />);

    // Duplicating is how a shared dashboard gets adapted without being changed for
    // everyone using it.
    expect(await screen.findByRole('button', { name: /Duplicate/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Edit$/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Delete$/ })).not.toBeInTheDocument();
  });

  it('explains an empty state and offers a way out of it', async () => {
    fetchDashboards.mockResolvedValue([]);

    await renderWithProviders(<DashboardsPage />);

    expect(await screen.findByText(/No dashboards yet/i)).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /New dashboard/i }).length).toBeGreaterThan(0);
  });

  it('offers no authoring controls to a reader who may only view', async () => {
    // Client viewers hold analytics.view but not saved_views.manage: they see what
    // was shared with them and cannot reshape it.
    permissions.current = ['analytics.view'];

    await renderWithProviders(<DashboardsPage />);

    expect(await screen.findByTestId('widget-bar')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /New dashboard/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Edit$/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Duplicate/i })).not.toBeInTheDocument();
  });

  it('refuses the surface entirely without analytics access', async () => {
    permissions.current = [];

    await renderWithProviders(<DashboardsPage />);

    expect(
      await screen.findByText(/role does not include access to performance analytics/i),
    ).toBeInTheDocument();
  });

  it('distinguishes a permission refusal from a transport failure', async () => {
    fetchDashboards.mockRejectedValue(new ApiError('Server error', 500));

    await renderWithProviders(<DashboardsPage />);

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
  });
});
