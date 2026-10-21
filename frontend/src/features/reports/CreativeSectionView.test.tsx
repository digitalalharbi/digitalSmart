import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { renderWithProviders } from '@/test/render';
import { CreativeSectionView } from './CreativeSectionView';
import type { CreativeSectionRow, CreativeViewValue, RenderedSection } from './types';

const value = (
  metric: string,
  number: number | null,
  format: MetricValue['format'] = 'count',
): MetricValue => ({
  metric,
  label: metric.toUpperCase(),
  value: number,
  format,
  precision: 0,
  is_minor_units: false,
  currency: null,
  source: 'synced',
  source_label: 'Synced',
  requires_label: false,
  unavailable_reason: number === null ? 'Not reported by the platform.' : null,
  comparison_value: null,
  change_ratio: null,
  change_is_good: null,
  lower_is_better: false,
});

const row = (
  position: number,
  label: string,
  over: Partial<CreativeSectionRow> = {},
): CreativeSectionRow => ({
  position,
  label,
  platform: 'meta',
  platform_label: 'Meta',
  format: 'image',
  format_label: 'Image',
  media: {
    state: 'available',
    kind: 'image',
    url: 'https://cdn.example-real.com/a.jpg',
    origin: 'provider',
  },
  ads: 1,
  campaigns: ['Eid campaign'],
  campaign_count: 1,
  metrics: {
    source: 'synced',
    currency: 'SAR',
    row_count: 1,
    is_empty: false,
    values: {
      spend: value('spend', 900 + position),
      ctr: value('ctr', 2.2, 'percentage'),
      conversions: value('conversions', 12),
      cpa: value('cpa', 40),
      frequency: value('frequency', null),
    },
  },
  trend: [
    { date: '2026-10-14', spend: 10, ctr: 2.4 },
    { date: '2026-10-15', spend: 11, ctr: 2.1 },
  ],
  verdict: 'top',
  verdict_label: 'Top',
  evidence: [],
  ...over,
});

function section(
  view: CreativeViewValue,
  rows: CreativeSectionRow[],
  over: Partial<RenderedSection> = {},
): RenderedSection {
  return {
    kind: 'creative',
    title: 'Top creatives',
    metrics: [],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: rows.length === 0,
    caveats: [],
    currency: 'SAR',
    totals: null,
    series: [],
    rows: [],
    creative: {
      view,
      total: rows.length,
      mix_is_spend: true,
      mix: [
        { key: 'image', label: 'Image', value: 700 },
        { key: 'video', label: 'Video', value: 300 },
      ],
      rows,
    },
    ...over,
  };
}

describe('CreativeSectionView', () => {
  it('draws top creatives as pictures with their figures, and the plain verdict only when it is not steady', async () => {
    await renderWithProviders(
      <CreativeSectionView
        section={section('top', [
          row(1, 'Eid film'),
          row(2, 'Quiet', { verdict: 'stable', verdict_label: 'Steady' }),
        ])}
      />,
    );

    expect(screen.getByRole('img', { name: 'Eid film' })).toHaveAttribute(
      'referrerpolicy',
      'no-referrer',
    );
    expect(screen.getByText('Eid film')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    // A client reads a report; nothing to compare or work on.
    expect(screen.getAllByText('Top').length).toBeGreaterThan(0);
  });

  it('says there is no preview rather than drawing one', async () => {
    await renderWithProviders(
      <CreativeSectionView
        section={section('top', [
          row(1, 'Sample', { media: { state: 'fixture', kind: 'none', url: null, origin: null } }),
          row(2, 'Blank', { media: { state: 'none', kind: 'none', url: null, origin: null } }),
        ])}
      />,
    );

    expect(screen.getByText('Sample data — no preview')).toBeInTheDocument();
    expect(screen.getByText('No preview from the platform')).toBeInTheDocument();
    expect(screen.queryAllByRole('img')).toHaveLength(0);
  });

  it('shows performance as a table with a dash for what was not reported', async () => {
    await renderWithProviders(
      <CreativeSectionView section={section('performance', [row(1, 'Eid film')])} />,
    );

    const body = within(screen.getAllByRole('table')[0] as HTMLElement);
    expect(body.getByText('Eid film')).toBeInTheDocument();
  });

  it('draws the format mix as shares, and compares only with two or more', async () => {
    const { unmount } = await renderWithProviders(
      <CreativeSectionView section={section('format_mix', [row(1, 'A')])} />,
    );
    expect(screen.getByText('by spend')).toBeInTheDocument();
    unmount();

    const one = await renderWithProviders(
      <CreativeSectionView section={section('comparison', [row(1, 'A')])} />,
    );
    expect(screen.getByText('A comparison needs at least two creatives.')).toBeInTheDocument();
    one.unmount();

    await renderWithProviders(
      <CreativeSectionView section={section('comparison', [row(1, 'A'), row(2, 'B')])} />,
    );
    expect(screen.getByRole('region', { name: 'Comparison' })).toBeInTheDocument();
  });

  it('counts creatives, not money, when the money is in two currencies', async () => {
    const mixed = section('format_mix', [row(1, 'A')]);
    mixed.creative = { ...mixed.creative!, mix_is_spend: false };
    await renderWithProviders(<CreativeSectionView section={mixed} />);

    expect(screen.getByText('by number of creatives')).toBeInTheDocument();
  });

  it('says so in words when there is nothing to show', async () => {
    await renderWithProviders(<CreativeSectionView section={section('refresh', [])} />);

    expect(screen.getByText('No creatives in this period')).toBeInTheDocument();
  });
});
