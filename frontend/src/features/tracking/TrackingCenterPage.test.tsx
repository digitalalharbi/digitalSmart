import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { TrackingCenterPage } from './TrackingCenterPage';
import type {
  EventDefinition,
  MeasurementSource,
  SyncResult,
  TrackingHealth,
  TrackingIssue,
} from './types';

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Noon Retail' }] },
  }),
}));

const state = vi.hoisted(() => ({
  health: null as TrackingHealth | null,
  // Shaped per test, so a sync can return a succeeded, partial, failed or skipped run.
  syncResult: null as SyncResult | null,
}));

vi.mock('./api', () => ({
  fetchTrackingHealth: () => Promise.resolve(state.health),
  fetchSources: () => Promise.resolve(state.health?.sources ?? []),
  fetchConversions: () => Promise.resolve(state.health?.definitions ?? []),
  fetchProperties: () => Promise.resolve([]),
  fetchSuggestions: () => Promise.resolve([]),
  syncSource: () => Promise.resolve(state.syncResult),
  createSource: () => Promise.resolve({}),
  deleteSource: () => Promise.resolve(),
  createConversion: () => Promise.resolve({}),
  updateConversion: () => Promise.resolve({}),
  deleteConversion: () => Promise.resolve(),
  fetchContainers: () => Promise.resolve([]),
  fetchReadiness: () => Promise.resolve({}),
}));

function source(overrides: Partial<MeasurementSource> = {}): MeasurementSource {
  return {
    id: 'source-1',
    client_id: 'client-1',
    client_name: 'Noon Retail',
    project_id: null,
    project_name: null,
    provider: 'google_analytics',
    provider_label: 'Google Analytics 4',
    kind: 'analytics',
    kind_label: 'Analytics property',
    external_id: 'properties/412006881',
    name: 'Noon Retail — Web',
    freshness: 'live',
    freshness_label: 'Live',
    needs_attention: false,
    is_revenue_authority: false,
    is_active: true,
    last_synced_at: '2026-10-06T08:00:00+00:00',
    last_event_at: '2026-10-06T07:00:00+00:00',
    ...overrides,
  };
}

function definition(overrides: Partial<EventDefinition> = {}): EventDefinition {
  return {
    id: 'definition-1',
    client_id: 'client-1',
    project_id: null,
    name: 'Qualified lead',
    normalized_event: 'generate_lead',
    normalized_event_label: 'Lead',
    is_conversion: true,
    is_primary: true,
    expects_value: false,
    is_intent_signal_only: false,
    description: null,
    mappings: [
      {
        id: 'mapping-1',
        raw_event_name: 'generate_lead',
        measurement_source_id: null,
        measurement_source_name: null,
      },
    ],
    observed_count: 37,
    last_seen_date: '2026-10-05',
    ...overrides,
  };
}

function issue(overrides: Partial<TrackingIssue> = {}): TrackingIssue {
  return {
    code: 'no_recent_events',
    severity: 'critical',
    severity_label: 'Critical',
    summary: 'Noon Retail — Web is reachable but has reported no events since 5 days ago.',
    remedy: 'Check that the tag is still deployed and firing on the live site.',
    subject: 'source-1',
    evidence: { last_event_at: '2026-10-01T09:00:00+00:00' },
    ...overrides,
  };
}

function health(overrides: Partial<TrackingHealth> = {}): TrackingHealth {
  return {
    is_healthy: true,
    critical_count: 0,
    window_days: 14,
    sources: [source()],
    definitions: [definition()],
    issues: [],
    ...overrides,
  };
}

beforeEach(() => {
  state.health = health();
  state.syncResult = null;
});

describe('the tracking centre', function () {
  it('says plainly when nothing needs attention', async () => {
    await renderWithProviders(<TrackingCenterPage />);

    /*
     * Silence is the goal, so it is said out loud. A blank area here would read as a loading
     * failure, and a page that always has something on it is a page nobody reads twice.
     */
    expect(await screen.findByText('Nothing needs attention')).toBeInTheDocument();
  });

  it('leads with what is wrong rather than with a tidy table', async () => {
    state.health = health({ is_healthy: false, critical_count: 1, issues: [issue()] });

    await renderWithProviders(<TrackingCenterPage />);

    const headings = await screen.findAllByRole('heading', { level: 2 });

    /*
     * Order is the product decision here. A page opening on a neat list of sources invites the
     * conclusion that everything is fine — which is exactly the conclusion that costs an agency a
     * quarter of a client's spend.
     */
    expect(headings[0]).toHaveTextContent('What needs attention');
  });

  it('shows the remedy and the evidence beside each problem', async () => {
    state.health = health({ is_healthy: false, issues: [issue()] });

    await renderWithProviders(<TrackingCenterPage />);

    expect(
      await screen.findByText('Check that the tag is still deployed and firing on the live site.'),
    ).toBeInTheDocument();

    /*
     * The evidence is on the page, not behind a tooltip. A tracking centre that says "no events"
     * without saying how it knows is one people stop believing after the first false alarm — and a
     * false alarm is certain, because the product only sees what a provider reports.
     */
    const issues = screen.getByRole('region', { name: 'What needs attention' });

    expect(within(issues).getByText('Last event')).toBeInTheDocument();
  });

  it('distinguishes a silent source from a healthy one', async () => {
    state.health = health({
      sources: [source({ freshness: 'silent', freshness_label: 'No recent events' })],
    });

    await renderWithProviders(<TrackingCenterPage />);

    // Working perfectly and measuring nothing. Carries the same visual weight as never connected,
    // because the consequence is the same: every report built on it is empty.
    expect(await screen.findByText('No recent events')).toBeInTheDocument();
  });

  it('marks a store as able to report revenue, and a property as not', async () => {
    state.health = health({
      sources: [
        source({
          id: 'store-1',
          name: 'Salla storefront',
          kind: 'store',
          is_revenue_authority: true,
        }),
        source(),
      ],
    });

    await renderWithProviders(<TrackingCenterPage />);

    /*
     * An analytics property reports what a browser said a sale was worth; a store reports what was
     * invoiced. Exactly one badge, so the difference is visible before anybody builds a revenue
     * report on the wrong one.
     */
    expect(await screen.findAllByText('Can report revenue')).toHaveLength(1);
  });

  it('shows a conversion that has never been seen as zero, and an unasked one as a dash', async () => {
    state.health = health({
      definitions: [
        definition({ observed_count: 0 }),
        definition({ id: 'definition-2', name: 'Sale', observed_count: null }),
      ],
    });

    await renderWithProviders(<TrackingCenterPage />);

    await screen.findByText('Qualified lead');

    // "Asked and never happened" and "nobody looked" are different facts, and rendering both as 0
    // would turn the second into the first.
    expect(screen.getByText('0')).toBeInTheDocument();
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('marks an intent signal as one', async () => {
    state.health = health({
      definitions: [
        definition({
          name: 'WhatsApp taps',
          normalized_event: 'whatsapp_click',
          is_intent_signal_only: true,
        }),
      ],
    });

    await renderWithProviders(<TrackingCenterPage />);

    // The distinction that stops a cost per click being read as a cost per lead.
    expect(await screen.findByText('Intent signal')).toBeInTheDocument();
  });

  it("reports a sync's caveats rather than just saying it worked", async () => {
    state.syncResult = {
      status: 'succeeded',
      days_read: 4,
      rows_written: 31,
      api_calls: 2,
      source: 'partial',
      source_label: 'Partial',
      caveats: [
        'Google Analytics analysed 8.0% of the available events, so the figures are an estimate.',
      ],
      last_event_date: '2026-10-05',
      failure_message: null,
    };

    await renderWithProviders(<TrackingCenterPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Read now' }));

    /*
     * A run can succeed completely and still have read estimates. The person who pressed the button
     * is the one who needs to know that before they quote the number, so a bare "synced" toast
     * would be the wrong design.
     */
    await waitFor(() => {
      expect(screen.getByText(/analysed 8.0% of the available events/)).toBeInTheDocument();
    });
  });

  it('says a failed read failed, and why', async () => {
    state.syncResult = {
      status: 'failed',
      days_read: 0,
      rows_written: 0,
      api_calls: 1,
      source: 'synced',
      source_label: 'Synced',
      caveats: [],
      last_event_date: null,
      failure_message: 'Google Analytics 4 has not completed its authorisation handshake.',
    };

    await renderWithProviders(<TrackingCenterPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Read now' }));

    await waitFor(() => {
      expect(
        screen.getByText('Google Analytics 4 has not completed its authorisation handshake.'),
      ).toBeInTheDocument();
    });
  });

  it('calls a source with nothing to read skipped, not broken', async () => {
    state.syncResult = {
      status: 'skipped',
      days_read: 0,
      rows_written: 0,
      api_calls: 0,
      source: 'synced',
      source_label: 'Synced',
      caveats: [],
      last_event_date: null,
      failure_message: null,
    };

    await renderWithProviders(<TrackingCenterPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Read now' }));

    // A hand-filled source has nothing to sync. Distinguishable from an error, or somebody will go
    // looking for a fault that does not exist.
    await waitFor(() => {
      expect(screen.getByText(/somebody fills in by hand/)).toBeInTheDocument();
    });
  });

  it('says nothing is measuring when nothing is', async () => {
    state.health = health({ sources: [], definitions: [], issues: [] });

    await renderWithProviders(<TrackingCenterPage />);

    expect(await screen.findByText('Nothing is measuring this work yet')).toBeInTheDocument();
    expect(screen.getByText('No conversion is defined')).toBeInTheDocument();
  });
});
