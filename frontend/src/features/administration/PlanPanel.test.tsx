import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { PlanPanel, type WorkspacePlan } from './PlanPanel';

const plan: WorkspacePlan = {
  plan: { code: 'starter', name: 'Starter' },
  features: [
    { key: 'overview', enabled: true, source: 'everywhere', everywhere: true },
    { key: 'automation', enabled: false, source: 'plan', everywhere: false },
  ],
  limits: [
    {
      key: 'clients',
      allowed: 5,
      used: 6,
      source: 'plan',
      monthly: false,
      resets_at: null,
      over: true,
      refuses: true,
    },
    {
      key: 'projects',
      allowed: 10,
      used: 3,
      source: 'override',
      monthly: false,
      resets_at: null,
      over: false,
      refuses: true,
    },
    {
      key: 'campaign_launches_monthly',
      allowed: 0,
      used: null,
      source: 'plan',
      monthly: true,
      resets_at: '2026-11-01T00:00:00+00:00',
      over: false,
      refuses: true,
    },
    {
      key: 'tracking_events_monthly',
      allowed: null,
      used: 120,
      source: 'plan',
      monthly: true,
      resets_at: '2026-11-01T00:00:00+00:00',
      over: false,
      refuses: false,
    },
  ],
  overrides: [
    {
      kind: 'limit',
      key: 'projects',
      value: '10',
      previous_value: '20',
      reason: 'Trial',
      changed_by: 'ops',
      at: '2026-10-20T10:00:00+00:00',
    },
  ],
};

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return { ...original, api: { get: () => Promise.resolve({ data: { data: plan } }) } };
});

describe('PlanPanel', () => {
  it('shows the plan, usage against each limit, and says plainly when nothing was removed', async () => {
    await renderWithProviders(<PlanPanel />);

    expect(await screen.findByRole('heading', { name: 'Starter' })).toBeInTheDocument();
    expect(screen.getByText('6 of 5')).toBeInTheDocument();
    expect(screen.getByText(/Nothing was removed/)).toHaveTextContent('Clients');
    expect(screen.getByText('Not measured yet')).toBeInTheDocument();
    expect(screen.getByText('120 used · no limit')).toBeInTheDocument();
    expect(screen.getByText(/Counted, never refused/)).toBeInTheDocument();
    expect(screen.getByText('Changed for this workspace')).toBeInTheDocument();
    expect(screen.getByText('20 → 10 · Trial')).toBeInTheDocument();
    expect(screen.getByText('Not in plan')).toBeInTheDocument();
  });
});
