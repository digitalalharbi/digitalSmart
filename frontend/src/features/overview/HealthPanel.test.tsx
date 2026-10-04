import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { HealthPanel } from './HealthPanel';
import { ApiError } from '@/shared/api/client';
import type { HealthReport } from './types';

const fetchHealth = vi.hoisted(() => vi.fn());

vi.mock('./api', async () => {
  const { useQuery } = await import('@tanstack/react-query');
  return {
    useHealthQuery: () => useQuery({ queryKey: ['system', 'health'], queryFn: fetchHealth }),
  };
});

const report: HealthReport = {
  application: 'digitalSmart',
  version: '0.1.0',
  environment: 'testing',
  status: 'ok',
  services: [
    { name: 'database', status: 'ok', detail: 'PostgreSQL 16' },
    { name: 'cache', status: 'ok', detail: 'redis' },
    { name: 'queue', status: 'degraded', detail: 'no worker heartbeat' },
  ],
};

describe('HealthPanel', () => {
  beforeEach(() => {
    fetchHealth.mockReset();
  });

  it('renders each service with its status once loaded', async () => {
    fetchHealth.mockResolvedValue(report);
    await renderWithProviders(<HealthPanel />);

    expect(await screen.findByText('database')).toBeInTheDocument();
    expect(screen.getByText('cache')).toBeInTheDocument();
    expect(screen.getByText('queue')).toBeInTheDocument();
    expect(screen.getByText('no worker heartbeat')).toBeInTheDocument();
    expect(screen.getAllByText('Operational').length).toBeGreaterThan(0);
    expect(screen.getByText('Degraded')).toBeInTheDocument();
  });

  it('shows an actionable error state when the API is unreachable', async () => {
    fetchHealth.mockRejectedValue(new ApiError('offline', null));
    await renderWithProviders(<HealthPanel />);

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('The API could not be reached.');
    expect(alert).toHaveTextContent('php artisan serve --port=8001');
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('shows a busy placeholder while loading', async () => {
    fetchHealth.mockImplementation(() => new Promise(() => undefined));
    await renderWithProviders(<HealthPanel />);

    await waitFor(() => {
      expect(screen.getByLabelText('Checking services…')).toBeInTheDocument();
    });
  });
});
