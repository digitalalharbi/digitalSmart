import { screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { SyncIndicator } from './SyncIndicator';

const get = vi.hoisted(() => vi.fn());
vi.mock('@/shared/api/client', () => ({ api: { get } }));

describe('SyncIndicator', () => {
  beforeEach(() => {
    get.mockReset();
  });

  it('says main and deployment in words, with the commit', async () => {
    get.mockResolvedValue({
      data: {
        data: {
          main: { state: 'green', label: 'green', sha: 'dc05b45' },
          deploy: { state: 'grey', label: 'waiting', sha: null },
        },
      },
    });
    await renderWithProviders(<SyncIndicator />);

    expect(await screen.findByText('green')).toBeInTheDocument();
    expect(screen.getByText('waiting')).toBeInTheDocument();
    expect(screen.getByText('dc05b45')).toBeInTheDocument();
  });

  it('says unavailable rather than showing a colour it has no basis for', async () => {
    get.mockImplementation(() => Promise.reject(new Error('offline')));
    await renderWithProviders(<SyncIndicator />);

    expect(await screen.findByText('Status unavailable')).toBeInTheDocument();
  });
});
