import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import type { CreativePayload, CreativeRow } from '@/features/creatives';
import { AdsPage } from './AdsPage';

const read = vi.hoisted(() => vi.fn());

vi.mock('@/features/creatives', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  useCreatives: read,
}));
vi.mock('@/features/clients', () => ({ useClientsQuery: () => ({ data: { data: [] } }) }));
vi.mock('@/features/surface-views', () => ({ SavedViewsMenu: () => null }));
vi.mock('@/features/integrations', () => ({
  useProvidersQuery: () => ({ data: [{ provider: 'meta', label: 'Meta' }] }),
  ProviderLogo: () => <span data-testid="logo" />,
}));

function row(overrides: Partial<CreativeRow> = {}): CreativeRow {
  return {
    key: 'a1',
    label: 'Video — Ramadan',
    platform: 'meta',
    platform_label: 'Meta',
    format: 'video',
    format_label: 'Video',
    media: { state: 'none', kind: 'none', url: null, origin: null },
    ads: 1,
    campaigns: ['Ramadan Push'],
    campaign_count: 1,
    ad_name: 'Video — Ramadan',
    ad_status: 'active',
    parents: {
      ad_group: { id: 'g1', name: 'Lookalike 1%' },
      campaign: { id: 'k1', name: 'Ramadan Push' },
    },
    metrics: {
      source: 'synced',
      source_label: 'Synced',
      currency: 'SAR',
      values: {},
    },
    trend: [],
    verdict: 'fatiguing',
    verdict_label: 'Fatiguing',
    evidence: [],
    ...overrides,
  };
}

function payload(rows: CreativeRow[], total = rows.length): CreativePayload {
  return {
    grain: 'ad',
    range: { start: '2026-10-01', end: '2026-10-28', days: 28 },
    total,
    has_more: total > rows.length,
    facets: {
      formats: [{ key: 'video', label: 'Video', count: rows.length }],
      platforms: [{ key: 'meta', label: 'Meta', count: rows.length }],
    },
    rows,
  };
}

function page(rows: CreativeRow[], total?: number) {
  return { data: payload(rows, total), isPending: false, isError: false, isFetching: false };
}

describe('AdsPage', () => {
  beforeEach(() => {
    read.mockReset();
    read.mockReturnValue(page([row()]));
  });

  it('opens on the pictures, each ad a card that leads to its own page', async () => {
    await renderWithProviders(<AdsPage />);
    const list = screen.getByRole('list', { name: 'Ads' });

    expect(within(list).getByRole('link', { name: 'Video — Ramadan' })).toHaveAttribute(
      'href',
      '/ads/a1',
    );
  });

  it('asks the creative layer for ads, narrowed by what the address names', async () => {
    await renderWithProviders(<AdsPage />, {
      route: '/ads?group=g1~Lookalike&verdict=fatiguing&status=active',
    });

    expect(read).toHaveBeenLastCalledWith(
      expect.objectContaining({
        grain: 'ad',
        ad_group_ids: ['g1'],
        verdict: 'fatiguing',
        ad_status: 'active',
      }),
    );
    expect(screen.getByText(/In ad group/)).toBeInTheDocument();
  });

  it('is a table on request, with each ad beside its ad group and its campaign', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<AdsPage />);

    await user.click(screen.getByRole('button', { name: 'Table' }));
    const table = await screen.findByRole('table', { name: 'Ads' });

    expect(within(table).getByRole('link', { name: 'Ramadan Push' })).toHaveAttribute(
      'href',
      '/campaigns/k1',
    );
    expect(within(table).getByRole('link', { name: 'Lookalike 1%' })).toHaveAttribute(
      'href',
      '/ad-groups/g1',
    );
    expect(within(table).getByText('Fatiguing')).toBeInTheDocument();
  });

  it('prints a dash for a figure nobody reported, never a zero', async () => {
    await renderWithProviders(<AdsPage />, { route: '/ads?view=table' });
    const table = await screen.findByRole('table', { name: 'Ads' });

    expect(within(table).getAllByText('—').length).toBeGreaterThanOrEqual(5);
    expect(within(table).queryByText('0')).not.toBeInTheDocument();
  });

  it('narrows by the creative’s own status', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<AdsPage />);

    await user.selectOptions(screen.getByLabelText('Creative status'), 'weak');

    expect(read).toHaveBeenLastCalledWith(expect.objectContaining({ verdict: 'weak' }));
  });

  it('says plainly when nothing matches, and offers to start over', async () => {
    read.mockReturnValue(page([]));
    await renderWithProviders(<AdsPage />, { route: '/ads?status=rejected' });

    expect(screen.getByText('No ads match these filters')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Clear all filters' })).toBeInTheDocument();
  });

  it('pages by the total the server counted, not the page it holds', async () => {
    read.mockReturnValue(page([row()], 100));
    await renderWithProviders(<AdsPage />);

    expect(screen.getByText('1–24 of 100')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
  });
});
