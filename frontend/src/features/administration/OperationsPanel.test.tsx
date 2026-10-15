import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { OperationsPanel } from './OperationsPanel';

const get = vi.hoisted(() => vi.fn());

vi.mock('@/shared/api/client', async () => ({
  ...(await vi.importActual<Record<string, unknown>>('@/shared/api/client')),
  api: { get: (...a: unknown[]) => get(...a) },
}));

const summary = {
  connections: { healthy: 4, needs_reconnect: 1, sync_issue: 0, awaiting_access: 2 },
  accounts_with_sync_trouble: 2,
  stale_accounts: 1,
  accounts_syncing: 30,
  last_sync_at: '2026-06-15T10:00:00Z',
  next_sync_at: null,
};

describe('OperationsPanel', () => {
  beforeEach(() => get.mockReset());

  it('shows plain counts and says the rest is for people who administer integrations', async () => {
    get.mockResolvedValue({ data: { data: { summary, advanced: null } } });
    await renderWithProviders(<OperationsPanel />);

    expect(await screen.findByText('Healthy connections')).toBeInTheDocument();
    expect(screen.getByText(/visible to people who administer integrations/)).toBeInTheDocument();
    expect(screen.queryByText('Advanced')).not.toBeInTheDocument();
  });

  it('shows queue depth, error rate and failing runs only when the server sent them', async () => {
    get.mockResolvedValue({
      data: {
        data: {
          summary,
          advanced: {
            queue_depth: { 'sync-recovery': 3, sync: 12 },
            providers: [{ provider: 'meta', runs: 10, failed: 2, partial: 0, error_rate: 0.2 }],
            failures: [
              {
                account_name: 'KSA Main',
                provider: 'meta',
                kind: 'insights',
                code: 'rate_limit',
                message: 'Slow down',
                at: '2026-06-15T09:00:00Z',
              },
            ],
            stale: [],
            window_hours: 24,
          },
        },
      },
    });
    await renderWithProviders(<OperationsPanel />);

    expect(await screen.findByText('sync-recovery')).toBeInTheDocument();
    expect(screen.getByText('rate_limit')).toBeInTheDocument();
    expect(screen.getByText('20.0%')).toBeInTheDocument();
  });

  it('says plainly when nothing has failed', async () => {
    get.mockResolvedValue({
      data: {
        data: {
          summary,
          advanced: { queue_depth: {}, providers: [], failures: [], stale: [], window_hours: 24 },
        },
      },
    });
    await renderWithProviders(<OperationsPanel />);

    expect(
      await screen.findByText(/No failed or partial syncs in the last 24 hours/),
    ).toBeInTheDocument();
  });
});
