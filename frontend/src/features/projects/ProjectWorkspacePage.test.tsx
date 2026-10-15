import { Route, Routes } from 'react-router';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUiStore } from '@/shared/store/ui-store';
import { renderWithProviders } from '@/test/render';
import { ProjectWorkspacePage } from './ProjectWorkspacePage';

const state = vi.hoisted(() => ({ failCampaigns: false }));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: {
      get: (url: string) => {
        if (url.endsWith('/readiness')) {
          return Promise.resolve({
            data: {
              data: {
                project: { id: 'p1', name: 'Ramadan', status: 'active' },
                overall: { state: 'needs_attention', label: 'Needs attention' },
                ready_count: 4,
                total: 7,
              },
            },
          });
        }

        if (url === '/campaigns/control') {
          return state.failCampaigns
            ? Promise.reject(new Error('forbidden'))
            : Promise.resolve({
                data: {
                  meta: {
                    health_counts: { critical: 2, needs_attention: 1 },
                    total: 14,
                    matched: 14,
                  },
                },
              });
        }

        return Promise.resolve({ data: { data: { funnel: { total: 30, open: 12, won: 5 } } } });
      },
    },
  };
});

function page() {
  return renderWithProviders(
    <Routes>
      <Route path="/projects/:projectId" element={<ProjectWorkspacePage />} />
      <Route path="/budget-control" element={<p>budget page</p>} />
    </Routes>,
    { route: '/projects/p1' },
  );
}

describe('ProjectWorkspacePage', () => {
  beforeEach(() => {
    state.failCampaigns = false;
    useUiStore.setState({ context: { client: null, project: null } });
  });

  it('shows this project’s own figures from the modules that own them', async () => {
    await page();

    expect(await screen.findByText('Ramadan')).toBeInTheDocument();
    expect(await screen.findByText('4 / 7')).toBeInTheDocument();
    expect(await screen.findByText('14')).toBeInTheDocument();
    expect(await screen.findByText('3 need attention')).toBeInTheDocument();
    expect(await screen.findByText('12 open · 5 won')).toBeInTheDocument();
  });

  it('says a figure is not available to the reader instead of showing zero', async () => {
    state.failCampaigns = true;
    await page();

    expect(await screen.findByText('Not available to you')).toBeInTheDocument();
  });

  it('opens an area with the project already set as the working context', async () => {
    await page();

    await userEvent.click(await screen.findByRole('button', { name: 'Budgets' }));

    expect(await screen.findByText('budget page')).toBeInTheDocument();
    expect(useUiStore.getState().context.project).toEqual({ id: 'p1', label: 'Ramadan' });
  });
});
