import { screen, within } from '@testing-library/react';
import { Route, Routes } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import type { CreativeRow } from '@/features/creatives';
import { AdDetailPage } from './AdDetailPage';

type State = { row: CreativeRow | null };

const state = vi.hoisted((): State => ({ row: null }));

vi.mock('@/features/creatives', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  useCreatives: () => ({
    isPending: false,
    data: { rows: state.row === null ? [] : [state.row] },
  }),
}));
vi.mock('@/features/analytics', () => ({
  HeroTrend: () => <div data-testid="trend" />,
  analyticsAsReport: () => ({ sections: [] }),
  useAnalytics: () => ({ data: { is_empty: false } }),
  useMetricCatalogue: () => ({ definitions: new Map() }),
}));
vi.mock('@/features/reports', () => ({ ReportHighlights: () => <div data-testid="funnel" /> }));
vi.mock('@/features/integrations', () => ({ ProviderLogo: () => <span /> }));

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
    metrics: { source: 'synced', source_label: 'Synced', currency: 'SAR', values: {} },
    trend: [],
    verdict: 'fatiguing',
    verdict_label: 'Fatiguing',
    evidence: [{ signal: 'ctr_change', value: -0.32 }],
    ...overrides,
  };
}

function page() {
  return renderWithProviders(
    <Routes>
      <Route path="/ads/:adId" element={<AdDetailPage />} />
    </Routes>,
    { route: '/ads/a1' },
  );
}

describe('AdDetailPage', () => {
  beforeEach(() => {
    state.row = row();
  });

  it('leads with the trail from the campaign through the ad group to the ad', async () => {
    await page();
    const trail = screen.getByRole('navigation', { name: 'Where you are' });

    expect(within(trail).getByRole('link', { name: 'Ramadan Push' })).toHaveAttribute(
      'href',
      '/campaigns/k1',
    );
    expect(within(trail).getByRole('link', { name: 'Lookalike 1%' })).toHaveAttribute(
      'href',
      '/ad-groups/g1',
    );
    expect(within(trail).getByText('Video — Ramadan')).toHaveAttribute('aria-current', 'page');
  });

  it('shows the fatigue verdict with the evidence behind it', async () => {
    await page();

    expect(screen.getAllByText('Fatiguing').length).toBeGreaterThan(0);
    expect(
      within(screen.getByRole('list', { name: 'What changed' })).getByText(/32%/),
    ).toBeInTheDocument();
  });

  it('shows a dash for a figure nobody reported, and no ROAS where there is none', async () => {
    await page();
    const figures = screen.getByRole('region', { name: 'Key figures' });

    expect(within(figures).queryByText('ROAS')).not.toBeInTheDocument();
    expect(within(figures).getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('says plainly when the ad is not one the reader may see', async () => {
    state.row = null;
    await page();

    expect(
      screen.getByText('This ad does not exist or is not one you may see.'),
    ).toBeInTheDocument();
  });
});
