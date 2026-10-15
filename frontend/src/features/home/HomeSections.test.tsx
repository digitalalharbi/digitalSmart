import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { HomeSections } from './HomeSections';

const get = vi.hoisted(() => vi.fn());

vi.mock('@/shared/api/client', async () => ({
  ...(await vi.importActual<Record<string, unknown>>('@/shared/api/client')),
  api: { get: (...a: unknown[]) => get(...a) },
}));
vi.mock('@/features/attention', () => ({ AttentionPanel: () => <div data-testid="attention" /> }));
vi.mock('@/features/portfolio', () => ({
  usePortfolio: () => ({ data: undefined, isPending: true }),
}));

describe('HomeSections', () => {
  beforeEach(() => {
    get.mockReset();
    get.mockImplementation((url: string) => {
      if (url === '/campaigns/control') {
        return Promise.resolve({
          data: {
            data: [
              {
                id: 'c1',
                name: 'Ramadan Push',
                client_name: 'Alhambra',
                project_name: 'Ramadan',
                health: 'critical',
                health_reason: 'Spending, and nothing has been recorded against it in this period.',
                spend_minor: 500_000,
                currency: 'SAR',
              },
            ],
          },
        });
      }

      if (url === '/budgets/control') {
        return Promise.resolve({
          data: {
            data: [
              {
                id: 'b1',
                name: 'Q2',
                client: { name: 'Alhambra' },
                currency: 'SAR',
                approved_minor: 5_000_000,
                spent_minor: null,
                risks: [{ risk: 'unallocated', label: 'Money not yet allocated' }],
              },
              {
                id: 'b2',
                name: 'Fine',
                client: { name: 'Najd' },
                currency: 'SAR',
                approved_minor: 1,
                spent_minor: 1,
                risks: [],
              },
            ],
          },
        });
      }

      return Promise.resolve({
        data: { data: { columns: [{ state: 'briefed', label: 'Briefed', requests: [{}, {}] }] } },
      });
    });
  });

  it('gives a media buyer their own campaigns, asked of the control center with mine=1', async () => {
    await renderWithProviders(<HomeSections profile="media_buyer" />);

    expect(await screen.findByText('Ramadan Push')).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith(
      '/campaigns/control',
      expect.objectContaining({ params: expect.objectContaining({ mine: 1 }) }),
    );
  });

  it('gives finance only the budgets that carry a risk', async () => {
    await renderWithProviders(<HomeSections profile="finance" />);

    expect(await screen.findByText('Q2')).toBeInTheDocument();
    expect(screen.queryByText('Fine')).not.toBeInTheDocument();
  });

  it('gives a creative the pipeline and no money', async () => {
    await renderWithProviders(<HomeSections profile="creative" />);

    const pipeline = await screen.findByText('Creative pipeline');
    expect(
      within(pipeline.closest('div') as HTMLElement).queryByText(/SAR/),
    ).not.toBeInTheDocument();
    expect(await screen.findByText('Briefed')).toBeInTheDocument();
    expect(get).not.toHaveBeenCalledWith('/budgets/control', expect.anything());
  });
});
