import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ContainerReadiness } from './ContainerReadiness';
import type { ContainerReadiness as Readiness, MeasurementSource } from './types';

const state = vi.hoisted(() => ({
  readiness: null as Readiness | null,
  isPending: false,
  error: null as Error | null,
  askedFor: null as string | null,
}));

vi.mock('./useTracking', () => ({
  useReadinessQuery: (sourceId: string | null) => {
    state.askedFor = sourceId;

    return {
      data: sourceId === null ? undefined : (state.readiness ?? undefined),
      isPending: sourceId !== null && state.isPending,
      error: state.error,
    };
  },
}));

function source(overrides: Partial<MeasurementSource> = {}): MeasurementSource {
  return {
    id: 'source-1',
    client_id: 'client-1',
    client_name: 'Noon Retail',
    project_id: null,
    project_name: null,
    provider: 'google_tag_manager',
    provider_label: 'Google Tag Manager',
    kind: 'tag_manager',
    kind_label: 'Tag container',
    external_id: 'accounts/1/containers/2',
    name: 'Noon — web',
    freshness: 'synced',
    freshness_label: 'Synced',
    needs_attention: false,
    is_revenue_authority: false,
    is_active: true,
    last_synced_at: '2026-10-07T09:00:00+00:00',
    last_event_at: null,
    ...overrides,
  };
}

function readiness(overrides: Partial<Readiness> = {}): Readiness {
  return {
    container: {
      container_id: '2',
      path: 'accounts/1/containers/2',
      name: 'Noon — web',
      public_id: 'GTM-ABC123',
      account_id: '1',
      usage_contexts: ['web'],
      is_web: true,
      domain_names: ['noon.example'],
      tag_ids: ['3', '4'],
      tag_manager_url: null,
    },
    is_ready: true,
    has_live_version: true,
    live_version_id: '7',
    has_unpublished_changes: false,
    published_tag_count: 2,
    critical_count: 0,
    measurement_ids_found: ['G-ABC123'],
    measurement_ids_expected: ['G-ABC123'],
    issues: [],
    ...overrides,
  };
}

beforeEach(() => {
  state.readiness = readiness();
  state.isPending = false;
  state.error = null;
  state.askedFor = null;
});

describe('tag container readiness', function () {
  it('asks the provider only once a container is chosen', async () => {
    await renderWithProviders(<ContainerReadiness sources={[source()]} canManage />);

    /*
     * Each answer is a live call to Tag Manager. A page that fired one per container on load would be slow in
     * proportion to how much a client has set up, which is the wrong way round.
     */
    expect(state.askedFor).toBeNull();

    await userEvent.click(screen.getByRole('button', { name: 'Noon — web' }));

    expect(state.askedFor).toBe('source-1');
  });

  it('separates published state from saved state', async () => {
    state.readiness = readiness({ has_unpublished_changes: true, is_ready: false });

    await renderWithProviders(<ContainerReadiness sources={[source()]} canManage />);
    await userEvent.click(screen.getByRole('button', { name: 'Noon — web' }));

    /*
     * The commonest reason a tag that looks correct is not firing, and the only screen that can tell the
     * difference: what is live is not what somebody is looking at in Tag Manager.
     */
    expect(await screen.findByText('Edited since publishing')).toBeInTheDocument();
    expect(screen.getByText('Not ready')).toBeInTheDocument();
  });

  it('cross-checks the measurement ids the client registered against the published ones', async () => {
    state.readiness = readiness({
      measurement_ids_expected: ['G-ABC123'],
      measurement_ids_found: ['G-ZZZ999'],
    });

    await renderWithProviders(<ContainerReadiness sources={[source()]} canManage />);
    await userEvent.click(screen.getByRole('button', { name: 'Noon — web' }));

    /*
     * The reason this panel is worth building. Expected comes from the client's own analytics sources, so a
     * container publishing a different property than the one somebody registered shows up here and nowhere else.
     */
    expect(await screen.findByText('G-ABC123')).toBeInTheDocument();
    expect(screen.getByText('G-ZZZ999')).toBeInTheDocument();
    expect(screen.getByText(/invisible everywhere else/)).toBeInTheDocument();
  });

  it("shows the provider's own words when the read fails, rather than no issues", async () => {
    const { ApiError } = await import('@/shared/api/client');

    state.readiness = null;
    state.error = new ApiError('Google Tag Manager could not be reached.', 502);

    await renderWithProviders(<ContainerReadiness sources={[source()]} canManage />);
    await userEvent.click(screen.getByRole('button', { name: 'Noon — web' }));

    /*
     * A container read that failed is not a container that is configured correctly. Reporting "no issues" after
     * a failed call is the worse of the two possible wrong answers.
     */
    expect(await screen.findByText(/could not be reached/)).toBeInTheDocument();
    expect(screen.queryByText('Ready')).not.toBeInTheDocument();
  });

  it('says a client has no container rather than showing an empty panel', async () => {
    await renderWithProviders(
      <ContainerReadiness sources={[source({ kind: 'analytics' })]} canManage />,
    );

    // An analytics property has no container, and saying so plainly beats a panel that looks broken.
    expect(screen.getByText('No tag container is registered for this client')).toBeInTheDocument();
  });

  it('explains itself to a reader without the setup permission', async () => {
    await renderWithProviders(<ContainerReadiness sources={[source()]} canManage={false} />);

    /*
     * Published rather than hidden: reading a container reaches the provider on the workspace's credentials,
     * and a reader who cannot see why the panel is empty assumes the containers are not connected.
     */
    expect(screen.getByText(/needs the tracking setup permission/)).toBeInTheDocument();
    expect(state.askedFor).toBeNull();
  });
});
