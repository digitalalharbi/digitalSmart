import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { ProjectSetupPage } from './ProjectSetupPage';

const get = vi.hoisted(() => vi.fn());

vi.mock('@/shared/api/client', async () => ({
  ...(await vi.importActual<Record<string, unknown>>('@/shared/api/client')),
  api: { get: (...a: unknown[]) => get(...a) },
}));
vi.mock('react-router', async () => ({
  ...(await vi.importActual<Record<string, unknown>>('react-router')),
  useParams: () => ({ projectId: 'p1' }),
}));

describe('ProjectSetupPage', () => {
  beforeEach(() => {
    get.mockReset();
    get.mockResolvedValue({
      data: {
        data: {
          project: { id: 'p1', name: 'Ramadan', status: 'onboarding' },
          overall: { state: 'blocked', label: 'Blocked' },
          ready_count: 2,
          total: 6,
          checks: [
            {
              key: 'budget',
              state: 'blocked',
              label: 'Blocked',
              reason: 'No approved budget yet.',
            },
            {
              key: 'accounts',
              state: 'ready',
              label: 'Ready',
              reason: '1 account connected and syncing.',
            },
            {
              key: 'tracking',
              state: 'needs_attention',
              label: 'Needs attention',
              reason: 'Measurement is reporting with warnings.',
            },
            {
              key: 'creative',
              state: 'ready',
              label: 'Ready',
              reason: 'Approved creative is available.',
            },
            { key: 'approvals', state: 'ready', label: 'Ready', reason: 'No decision is stuck.' },
            {
              key: 'landing_page',
              state: 'unknown',
              label: 'Not tracked',
              reason: 'Landing pages are not tracked in digitalSmart yet.',
            },
          ],
        },
      },
    });
  });

  it('shows progress and one sentence per question', async () => {
    await renderWithProviders(<ProjectSetupPage />);

    expect(await screen.findByText(/2 of 6 ready/)).toBeInTheDocument();
    expect(screen.getByText('No approved budget yet.')).toBeInTheDocument();
  });

  /* A link for what can be settled; nothing for what is done, and nothing invented for what cannot be seen. */
  it('offers a fix only for what is not ready, and none for what is not tracked', async () => {
    await renderWithProviders(<ProjectSetupPage />);
    await screen.findByText(/2 of 6 ready/);

    expect(screen.getAllByRole('link', { name: 'Settle this' })).toHaveLength(2);
    expect(screen.getByText('Not tracked')).toBeInTheDocument();
  });
});
