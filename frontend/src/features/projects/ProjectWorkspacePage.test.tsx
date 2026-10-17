import { Route, Routes } from 'react-router';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUiStore } from '@/shared/store/ui-store';
import { renderWithProviders } from '@/test/render';
import { ProjectWorkspacePage } from './ProjectWorkspacePage';

const state = vi.hoisted(() => ({ failCampaigns: false }));

const ok = (data: unknown) => Promise.resolve({ data });

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: {
      get: (url: string) => {
        if (url === '/projects/p1') {
          return ok({
            data: {
              id: 'p1',
              name: 'Ramadan',
              status: 'active',
              currency: 'SAR',
              starts_on: '2026-09-01',
              ends_on: null,
              is_demo: true,
              client: { id: 'c1', name: 'Alhambra Retail' },
            },
          });
        }

        if (url === '/projects/p1/members') {
          return ok({
            data: [
              {
                user_id: 'u1',
                name: 'Sara Haddad',
                role_slug: 'project_manager',
                role_name: 'Project manager',
              },
              {
                user_id: 'u2',
                name: 'Omar Nasser',
                role_slug: 'media_buyer',
                role_name: 'Media buyer',
              },
            ],
          });
        }

        if (url.endsWith('/readiness')) {
          return ok({
            data: {
              project: { id: 'p1', name: 'Ramadan', status: 'active' },
              overall: { state: 'needs_attention', label: 'Needs attention' },
              ready_count: 4,
              total: 7,
            },
          });
        }

        if (url === '/campaigns/control') {
          return state.failCampaigns
            ? Promise.reject(new Error('forbidden'))
            : ok({
                data: [
                  {
                    id: 'k1',
                    name: 'Ramadan Search',
                    health: 'critical',
                    health_reason: 'Spend is up and conversions stopped',
                    spend_minor: 120000,
                    currency: 'SAR',
                  },
                ],
                meta: { health_counts: { critical: 2, needs_attention: 1 }, total: 14 },
              });
        }

        if (url === '/budgets/control') {
          return ok({
            data: [
              {
                id: 'b1',
                name: 'Ramadan budget',
                currency: 'SAR',
                approved_minor: 1000000,
                allocated_minor: 900000,
                spent_minor: 400000,
                pacing: { status: 'on_track', status_label: 'On track', utilisation: 0.4 },
                risks: [],
              },
            ],
          });
        }

        if (url === '/activity') {
          return ok({
            data: [
              {
                id: 'a1',
                sentence: 'Ramadan moved to active',
                actor: 'Sara',
                project_id: 'p1',
                at: '2026-10-14T10:00:00Z',
              },
              {
                id: 'a2',
                sentence: 'Another project event',
                actor: 'Omar',
                project_id: 'other',
                at: '2026-10-14T11:00:00Z',
              },
            ],
          });
        }

        if (url === '/attention') {
          return ok({
            data: [
              {
                id: 'x1',
                message: 'Pause Ramadan Search',
                severity: 'critical',
                project_id: 'p1',
                action: { path: '/campaigns/k1' },
              },
              {
                id: 'x2',
                message: 'Not ours',
                severity: 'watch',
                project_id: 'other',
                action: { path: '/tasks' },
              },
            ],
          });
        }

        if (url === '/tasks/board') {
          return ok({ data: { columns: [], workload: { open: 0, overdue: 0 } } });
        }

        if (url === '/creative/board') {
          return ok({ data: { columns: [] } });
        }

        if (url === '/approvals/queue') {
          return ok({ data: { pending: [] } });
        }

        if (url === '/reports') {
          return ok({ data: [] });
        }

        return ok({ data: { funnel: { total: 30, open: 12, won: 5 } } });
      },
    },
  };
});

function page(route = '/projects/p1') {
  return renderWithProviders(
    <Routes>
      <Route path="/projects/:projectId" element={<ProjectWorkspacePage />} />
    </Routes>,
    { route },
  );
}

describe('ProjectWorkspacePage', () => {
  beforeEach(() => {
    state.failCampaigns = false;
    useUiStore.setState({ context: { client: null, project: null } });
  });

  it('keeps the scope in the header: client, project, owner, readiness and campaign health', async () => {
    await page();

    expect(await screen.findByRole('heading', { level: 1, name: 'Ramadan' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Alhambra Retail' })).toBeInTheDocument();
    expect((await screen.findAllByText('Sara Haddad')).length).toBeGreaterThan(0);
    expect(await screen.findByText(/4 \/ 7/)).toBeInTheDocument();
    expect(await screen.findByText(/3 need attention/)).toBeInTheDocument();
  });

  it('makes the project the working context once it is read', async () => {
    await page();
    await screen.findByRole('heading', { level: 1, name: 'Ramadan' });

    expect(useUiStore.getState().context.project).toEqual({ id: 'p1', label: 'Ramadan' });
    expect(useUiStore.getState().context.client).toEqual({ id: 'c1', label: 'Alhambra Retail' });
  });

  it('lists next actions for this project only', async () => {
    await page();

    expect(await screen.findByText('Pause Ramadan Search')).toBeInTheDocument();
    expect(screen.queryByText('Not ours')).not.toBeInTheDocument();
  });

  it('says a section is not available to the reader instead of showing zero', async () => {
    state.failCampaigns = true;
    await page();

    expect((await screen.findAllByText('Not available to you')).length).toBeGreaterThan(0);
  });

  it('switches tabs without leaving the project', async () => {
    await page();
    const user = userEvent.setup();

    await user.click(await screen.findByRole('tab', { name: 'Budget' }));

    expect(screen.getByRole('tab', { name: 'Budget' })).toHaveAttribute('aria-selected', 'true');
    expect(await screen.findByText('Ramadan budget')).toBeInTheDocument();
  });

  it('lists only this project’s recent events', async () => {
    await page('/projects/p1?tab=activity');

    expect((await screen.findAllByText(/Ramadan moved to active/)).length).toBeGreaterThan(0);
    expect(screen.queryByText(/Another project event/)).not.toBeInTheDocument();
  });
});
