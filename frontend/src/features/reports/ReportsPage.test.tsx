import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { MetricValue } from '@/shared/metrics';
import { renderWithProviders } from '@/test/render';
import { ReportsPage } from './ReportsPage';
import type {
  RenderedReport,
  RenderedSection,
  ReportDraft,
  ReportShare,
  ReportSnapshot,
  ReportSummary,
  ReportVocabulary,
} from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Alhambra' }] },
  }),
}));

const permissions = vi.hoisted(() => ({
  current: ['reports.view', 'reports.manage', 'reports.share'] as string[],
}));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: {
    reportsView: 'reports.view',
    reportsManage: 'reports.manage',
    reportsShare: 'reports.share',
  },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

const state = vi.hoisted(() => ({
  vocabulary: null as ReportVocabulary | null,
  list: [] as ReportSummary[],
  rendered: null as RenderedReport | null,
  draft: null as ReportDraft | null,
  snapshots: [] as ReportSnapshot[],
  shares: [] as ReportShare[],
  created: null as Record<string, unknown> | null,
  shared: null as Record<string, unknown> | null,
  revoked: null as Record<string, unknown> | null,
  shareFailsWith: null as Error | null,
}));

vi.mock('./api', () => ({
  fetchReportVocabulary: () => Promise.resolve(state.vocabulary),
  fetchReports: () => Promise.resolve(state.list),
  fetchReport: () => Promise.resolve(state.rendered),
  fetchReportDraft: () => Promise.resolve(state.draft),
  fetchSnapshots: () => Promise.resolve(state.snapshots),
  fetchShares: () => Promise.resolve(state.shares),
  fetchExports: () => Promise.resolve([]),
  fetchSchedules: () => Promise.resolve([]),
  createSchedule: () => Promise.resolve({ id: 'schedule-1' }),
  deleteSchedule: () => Promise.resolve(),
  requestExport: () => Promise.resolve({ id: 'export-1' }),
  exportDownloadUrl: (id: string) => `/api/exports/${id}/download`,
  createReport: (input: Record<string, unknown>) => {
    state.created = input;

    return Promise.resolve({ ...(state.draft as ReportDraft), id: 'report-1' });
  },
  updateReport: () => Promise.resolve(state.draft),
  deleteReport: () => Promise.resolve(),
  takeSnapshot: () => Promise.resolve({ id: 'snapshot-1' }),
  shareReport: (input: Record<string, unknown>) => {
    state.shared = input;

    if (state.shareFailsWith !== null) {
      return Promise.reject(state.shareFailsWith);
    }

    return Promise.resolve({ share: state.shares[0], token: 'a'.repeat(96) });
  },
  revokeShare: (input: Record<string, unknown>) => {
    state.revoked = input;

    return Promise.resolve();
  },
  csvUrl: (id: string) => `/api/reports/${id}/csv`,
  xlsxUrl: (id: string) => `/api/reports/${id}/xlsx`,
}));

function vocabulary(): ReportVocabulary {
  return {
    windows: [
      {
        window: 'last_30_days',
        label: 'The last 30 days',
        needs_dates: false,
        is_incomplete: false,
      },
      {
        window: 'month_to_date',
        label: 'This month so far',
        needs_dates: false,
        is_incomplete: true,
      },
      { window: 'custom', label: 'A specific period', needs_dates: true, is_incomplete: false },
    ],
    comparisons: [
      { comparison: 'none', label: 'No comparison', caveat: null },
      {
        comparison: 'previous_period',
        label: 'The period before',
        caveat: 'A change against the period before cannot tell seasonality from performance.',
      },
    ],
    audiences: [
      { audience: 'internal', label: 'Internal', allows_internal_notes: true },
      {
        audience: 'client_safe',
        label: 'Safe to send to the client',
        allows_internal_notes: false,
      },
    ],
    sections: [
      {
        kind: 'scorecard',
        label: 'Headline figures',
        needs_dimension: false,
        supports_comparison: true,
        metric_cap: 6,
        uses_metrics: true,
        metrics: ['spend', 'clicks', 'reach'],
      },
      {
        kind: 'breakdown',
        label: 'A ranking',
        needs_dimension: true,
        supports_comparison: false,
        metric_cap: 3,
        uses_metrics: true,
        /* Reach is absent: its rows do not add up, so the server will not accept it here. */
        metrics: ['spend', 'clicks'],
      },
    ],
    metrics: [
      {
        metric: 'spend',
        label: 'Spend',
        format: 'currency',
        is_monetary: true,
        lower_is_better: false,
        is_additive: true,
      },
      {
        metric: 'clicks',
        label: 'Clicks',
        format: 'count',
        is_monetary: false,
        lower_is_better: false,
        is_additive: true,
      },
      {
        metric: 'reach',
        label: 'Reach',
        format: 'count',
        is_monetary: false,
        lower_is_better: false,
        is_additive: false,
      },
    ],
    dimensions: [{ dimension: 'provider', label: 'Platform' }],
    revenue_sources: [
      { source: 'salla', label: 'Salla', is_invoiced: true, is_modelled: false },
      { source: 'attributed', label: 'Attributed', is_invoiced: false, is_modelled: true },
    ],
    max_share_days: 90,
  };
}

/* The full wire shape, so the fixture cannot drift from what `MetricValueResource` actually emits. */
function metricValue(overrides: Partial<MetricValue> = {}): MetricValue {
  return {
    metric: 'spend',
    label: 'Spend',
    value: 125_000,
    format: 'currency' as const,
    precision: 2,
    is_minor_units: true,
    currency: 'SAR',
    source: 'synced' as const,
    source_label: 'Synced',
    requires_label: false,
    unavailable_reason: null,
    comparison_value: 100_000,
    change_ratio: 0.25,
    change_is_good: true,
    lower_is_better: false,
    ...overrides,
  };
}

function section(overrides: Partial<RenderedSection> = {}): RenderedSection {
  return {
    kind: 'scorecard',
    title: 'Headline figures',
    metrics: ['spend'],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    caveats: ['A change against the period before cannot tell seasonality from performance.'],
    currency: 'SAR',
    totals: {
      values: { spend: metricValue() },
      source: 'synced',
      currency: 'SAR',
      row_count: 30,
      is_empty: false,
    },
    series: [],
    rows: [],
    ...overrides,
  };
}

function rendered(overrides: Partial<RenderedReport> = {}): RenderedReport {
  return {
    id: 'report-1',
    name: 'Monthly performance',
    description: 'What the month did.',
    client_name: 'Alhambra',
    period: { from: '2026-09-08', until: '2026-10-07', days: 30, is_incomplete: false },
    comparison: {
      mode: 'previous_period',
      label: 'The period before',
      from: '2026-08-09',
      until: '2026-09-07',
      was_requested: true,
      is_comparable: true,
      reason: null,
      code: null,
    },
    audience: 'internal',
    mode: 'live',
    mode_label: 'Live — recalculated every time it is opened',
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    internal_note: 'Do not mention the churn risk on the call.',
    rendered_at: '2026-10-08T09:00:00+00:00',
    sections: [section()],
    ...overrides,
  };
}

function summary(overrides: Partial<ReportSummary> = {}): ReportSummary {
  return {
    id: 'report-1',
    name: 'Monthly performance',
    description: 'What the month did.',
    window: 'last_30_days',
    window_label: 'The last 30 days',
    audience: 'internal',
    client_name: 'Alhambra',
    section_count: 1,
    snapshot_count: 2,
    active_share_count: 1,
    updated_at: '2026-10-08T09:00:00+00:00',
    ...overrides,
  };
}

function draft(overrides: Partial<ReportDraft> = {}): ReportDraft {
  return {
    id: 'report-1',
    name: 'Monthly performance',
    description: null,
    client_id: 'client-1',
    client_name: 'Alhambra',
    window: 'last_30_days',
    custom_from: null,
    custom_until: null,
    comparison: 'previous_period',
    audience: 'internal',
    internal_note: null,
    filters: { providers: [], campaign_ids: [], project_ids: [], client_id: 'client-1' },
    sections: [
      { kind: 'scorecard', title: null, metrics: ['spend'], dimension: null, row_limit: null },
    ],
    created_by_name: 'Reem',
    updated_at: '2026-10-08T09:00:00+00:00',
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['reports.view', 'reports.manage', 'reports.share'];
  state.vocabulary = vocabulary();
  state.list = [summary()];
  state.rendered = rendered();
  state.draft = draft();
  state.snapshots = [
    {
      id: 'snapshot-1',
      taken_at: '2026-10-01T09:00:00+00:00',
      period_from: '2026-09-01',
      period_until: '2026-09-30',
      source: 'synced',
      source_label: 'Synced',
      label: 'September close',
      taken_by_name: 'Reem',
    },
  ];
  state.shares = [
    {
      id: 'share-1',
      hint: 'abc123def456',
      state: 'active',
      state_label: 'Active',
      is_frozen: false,
      expires_at: '2026-10-15T09:00:00+00:00',
      revoked_at: null,
      revoked_reason: null,
      recipient_label: 'Alhambra',
      view_count: 3,
      last_viewed_at: '2026-10-08T09:00:00+00:00',
      created_by_name: 'Reem',
      created_at: '2026-10-08T09:00:00+00:00',
    },
  ];
  state.created = null;
  state.shared = null;
  state.revoked = null;
  state.shareFailsWith = null;
});

describe('the report list', function () {
  it('says how many live links each report has', async () => {
    await renderWithProviders(<ReportsPage />);

    const list = await screen.findByRole('region', { name: 'Saved reports' });

    /*
     * The figure somebody checks before saying "nobody outside can see this". On the card, not behind a click.
     */
    expect(within(list).getByText('1 live link')).toBeInTheDocument();
    expect(within(list).getByText('Internal')).toBeInTheDocument();
  });

  it('explains what a report is when there are none', async () => {
    state.list = [];

    await renderWithProviders(<ReportsPage />);

    expect(await screen.findByText('No reports yet')).toBeInTheDocument();
    expect(screen.getByText(/stays useful next month/)).toBeInTheDocument();
  });
});

describe('reading a report', function () {
  it('carries the period, the source and when it was rendered', async () => {
    await renderWithProviders(<ReportsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    // A figure with no timestamp is a figure somebody will quote next month.
    expect(await screen.findByText(/Figures as at/)).toBeInTheDocument();
    expect(screen.getByText(/2026-09-08 – 2026-10-07/)).toBeInTheDocument();
    expect(screen.getByText(/Live — recalculated/)).toBeInTheDocument();
  });

  it('says why there are no change figures, in their place', async () => {
    state.rendered = rendered({
      comparison: {
        mode: 'previous_period',
        label: 'The period before',
        from: '2026-09-01',
        until: '2026-09-30',
        was_requested: true,
        is_comparable: false,
        reason:
          'These periods are different lengths — 30 days against 31 — so a change between them measures the calendar as much as the campaigns.',
        code: 'unequal_length',
      },
    });

    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    /*
     * The sentence goes where the percentages would have been. A reader who finds no comparison and no explanation
     * does the arithmetic themselves, against whatever period they happen to pick.
     */
    expect(
      await screen.findByText(/measures the calendar as much as the campaigns/),
    ).toBeInTheDocument();
  });

  it('says a period is still running', async () => {
    state.rendered = rendered({
      period: { from: '2026-10-01', until: '2026-10-07', days: 7, is_incomplete: true },
    });

    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    expect(await screen.findByText(/the figures will move for the rest of it/)).toBeInTheDocument();
  });

  it('says nothing was recorded rather than showing zeroes', async () => {
    state.rendered = rendered({ is_empty: true, sections: [section({ is_empty: true })] });

    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    // Zero spend and "nothing recorded" mean opposite things to anybody deciding a budget.
    expect(await screen.findByText('Nothing recorded in this period')).toBeInTheDocument();
    expect(screen.getByText(/not the same as a total of zero/)).toBeInTheDocument();
  });

  it('attaches a caveat to the section it is about', async () => {
    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    const frame = await screen.findByRole('region', { name: 'Headline figures' });

    // Not a footnote on the document nobody reads the bottom of.
    expect(within(frame).getByText(/cannot tell seasonality from performance/)).toBeInTheDocument();
  });

  it('offers the figures as a spreadsheet as well as a CSV', async () => {
    /*
     * Both, not one. A CSV is what a script reads and what a spreadsheet mangles: every cell arrives as text,
     * so a column of spend is retyped by whoever opens it. The workbook is the same render with types on it.
     */
    await renderWithProviders(<ReportsPage />);

    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    const csv = await screen.findByRole('link', { name: 'Download CSV' });
    const xlsx = screen.getByRole('link', { name: 'Download Excel' });

    expect(csv).toHaveAttribute('href', '/api/reports/report-1/csv');
    expect(xlsx).toHaveAttribute('href', '/api/reports/report-1/xlsx');
  });

  it('offers every section as a table', async () => {
    state.rendered = rendered({
      sections: [
        section({
          kind: 'breakdown',
          title: 'Spend by platform',
          dimension: 'provider',
          dimension_label: 'Platform',
          totals: null,
          rows: [
            {
              key: 'meta',
              label: 'Meta',
              status: null,
              metrics: {
                values: { spend: metricValue() },
                source: 'synced',
                currency: 'SAR',
                row_count: 1,
                is_empty: false,
              },
            },
          ],
        }),
      ],
    });

    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    const frame = await screen.findByRole('region', { name: 'Spend by platform' });
    await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

    expect(within(frame).getByRole('row', { name: /Meta/ })).toBeInTheDocument();
  });
});

/** Open the first saved report and its full editor — where every shape, metric and period is chosen one at a time. */
async function openEditor(): Promise<void> {
  await renderWithProviders(<ReportsPage />);
  await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));
  await userEvent.click(await screen.findByRole('button', { name: 'Edit' }));
}

describe('editing one in the full editor', function () {
  it('does not offer a metric the shape will not accept', async () => {
    await openEditor();

    const sections = await screen.findByRole('group', { name: 'Metrics' });

    // Reach in a scorecard is fine.
    expect(within(sections).getByRole('button', { name: 'Reach' })).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'A ranking' }));

    /*
     * And absent in a ranking, because its rows do not add up. Offering it and then refusing on save would teach
     * people to distrust the form.
     */
    const afterSwitch = screen.getByRole('group', { name: 'Metrics' });

    expect(within(afterSwitch).queryByRole('button', { name: 'Reach' })).not.toBeInTheDocument();
    expect(within(afterSwitch).getByRole('button', { name: 'Spend' })).toBeInTheDocument();
  });

  it('shows what a comparison cannot account for while it is being chosen', async () => {
    await openEditor();
    await userEvent.click(await screen.findByRole('button', { name: 'The period before' }));

    // Part of deciding whether to ask for one, rather than a footnote after the report is read.
    expect(await screen.findByText(/cannot tell seasonality from performance/)).toBeInTheDocument();
  });

  it('asks for dates only for a specific period', async () => {
    await openEditor();
    await screen.findByRole('group', { name: 'Metrics' });

    expect(screen.queryByLabelText('From')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'A specific period' }));

    expect(await screen.findByLabelText('From')).toBeInTheDocument();
    expect(screen.getByLabelText('Until')).toBeInTheDocument();
  });
});

describe('building one with the wizard', function () {
  async function toShare(preset: string): Promise<void> {
    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'New report' }));

    // Client → type → period → content → preview → who it is for.
    await userEvent.click(await screen.findByRole('button', { name: 'Next' }));
    await userEvent.click(await screen.findByRole('button', { name: new RegExp(preset) }));
    for (let step = 0; step < 4; step += 1) {
      await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    }
  }

  it('starts from a preset and does not decide who the report is for', async () => {
    await toShare('Executive');

    /*
     * No default. A default of client-safe would mean a report written in shorthand becoming shareable by omission,
     * so the last button stays off until somebody has said who it is for.
     */
    const create = await screen.findByRole('button', { name: 'Create report' });

    expect(create).toBeDisabled();

    await userEvent.click(screen.getByRole('button', { name: /For the team/ }));

    expect(create).toBeEnabled();

    await userEvent.click(create);

    expect(state.created).toMatchObject({
      audience: 'internal',
      window: 'last_30_days',
      comparison: 'previous_period',
    });
    expect((state.created?.['sections'] as unknown[]).length).toBeGreaterThan(0);
  });

  it("offers the presets that read one client's own records only once there is a client", async () => {
    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'New report' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Next' }));

    expect(await screen.findByRole('button', { name: /Lead generation/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: /E-commerce/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: /Executive/ })).toBeEnabled();
  });

  it("lets a preset's sections be taken out before anything is saved", async () => {
    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'New report' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Next' }));
    await userEvent.click(await screen.findByRole('button', { name: /Executive/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    await userEvent.click(screen.getByRole('button', { name: 'Next' }));

    const before = screen.getAllByRole('button', { name: 'Remove this section' }).length;
    await userEvent.click(screen.getAllByRole('button', { name: 'Remove this section' })[0]!);

    expect(screen.getAllByRole('button', { name: 'Remove this section' })).toHaveLength(before - 1);
    expect(state.created).toBeNull();
  });
});

describe('sharing one', function () {
  it('says an internal report cannot be shared, rather than hiding the control', async () => {
    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    const panel = await screen.findByRole('region', { name: 'Links out' });

    /*
     * Hiding it produces a support question; the sentence produces an edit. And it says what "client-safe" means —
     * a document written to be read by the client, not the internal one with the notes removed.
     */
    expect(within(panel).getByText(/cannot be shared/)).toBeInTheDocument();
    expect(
      within(panel).queryByRole('button', { name: 'Create the link' }),
    ).not.toBeInTheDocument();
  });

  it('shows the link once and says it is the only time', async () => {
    state.list = [summary({ audience: 'client_safe' })];
    state.rendered = rendered({ audience: 'client_safe', internal_note: null });

    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    await userEvent.click(await screen.findByRole('button', { name: 'Create the link' }));

    /*
     * There is no endpoint that returns the token again — a link somebody lost is a link they should replace — and a
     * panel that did not say so would have people closing it expecting to return.
     */
    expect(await screen.findByText(/only time the link is shown/)).toBeInTheDocument();
    expect(screen.getByText(new RegExp('a'.repeat(20)))).toBeInTheDocument();
  });

  it('asks why before turning a link off', async () => {
    state.list = [summary({ audience: 'client_safe' })];
    state.rendered = rendered({ audience: 'client_safe', internal_note: null });

    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    await userEvent.click(await screen.findByRole('button', { name: 'Turn off' }));

    expect(state.revoked).toBeNull();

    await userEvent.type(
      await screen.findByLabelText(/Why it is being turned off/),
      'Sent to the wrong address.',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Turn it off' }));

    // The only record of what went wrong with a link.
    expect(state.revoked).toMatchObject({ reason: 'Sent to the wrong address.' });
  });

  it('says what a snapshot is for', async () => {
    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    const panel = await screen.findByRole('region', { name: 'Snapshots' });

    expect(within(panel).getByText(/never recalculates them/)).toBeInTheDocument();
  });

  it('offers no sharing to somebody who may only build', async () => {
    permissions.current = ['reports.view', 'reports.manage'];
    state.list = [summary({ audience: 'client_safe' })];
    state.rendered = rendered({ audience: 'client_safe', internal_note: null });

    await renderWithProviders(<ReportsPage />);
    await userEvent.click(await screen.findByRole('button', { name: /Monthly performance/ }));

    await screen.findByRole('region', { name: 'Links out' });

    /*
     * A buyer reporting on their own performance to a client, with nobody reading it first, is the one review this
     * module keeps. Hiding is the courtesy; the server refuses regardless.
     */
    expect(screen.queryByRole('button', { name: 'Create the link' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Turn off' })).not.toBeInTheDocument();
  });

  it('explains itself to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<ReportsPage />);

    expect(await screen.findByText('Reports are not part of your access')).toBeInTheDocument();
  });
});
