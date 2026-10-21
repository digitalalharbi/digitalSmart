import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { renderWithProviders } from '@/test/render';
import { CreativeSlide } from './CreativeSlide';
import type { CreativeSectionRow, RenderedSection } from './types';

const value = (metric: string, number: number | null): MetricValue => ({
  metric,
  label: metric.toUpperCase(),
  value: number,
  format: 'count',
  precision: 0,
  is_minor_units: false,
  currency: null,
  source: 'synced',
  source_label: 'Synced',
  requires_label: false,
  unavailable_reason: null,
  comparison_value: null,
  change_ratio: null,
  change_is_good: null,
  lower_is_better: false,
});

const row = (over: Partial<CreativeSectionRow> = {}): CreativeSectionRow => ({
  position: 1,
  label: 'Eid film',
  platform: 'meta',
  platform_label: 'Meta',
  format: 'video',
  format_label: 'Video',
  media: {
    state: 'available',
    kind: 'video',
    url: 'https://cdn.example-real.com/poster.jpg',
    origin: 'provider',
  },
  ads: 1,
  campaigns: ['Eid campaign'],
  campaign_count: 1,
  metrics: {
    values: {
      spend: value('spend', 900),
      ctr: value('ctr', 2),
      cpa: value('cpa', 40),
      conversions: value('conversions', null),
    },
    source: 'synced',
    currency: 'SAR',
    row_count: 1,
    is_empty: false,
  },
  trend: [],
  verdict: 'fatiguing',
  verdict_label: 'Fatiguing',
  evidence: [{ signal: 'ctr_change', value: -0.32 }],
  ...over,
});

const section = { title: 'Creatives to refresh' } as RenderedSection;

describe('CreativeSlide', () => {
  it('shows a video as its poster only, large, with its figures and what is wrong', async () => {
    const { container } = await renderWithProviders(
      <CreativeSlide section={section} row={row()} n={2} of={5} />,
    );

    expect(screen.getByRole('img', { name: 'Eid film' })).toHaveAttribute(
      'src',
      'https://cdn.example-real.com/poster.jpg',
    );
    expect(container.querySelector('video')).toBeNull();
    expect(screen.getByText('Fatiguing')).toBeInTheDocument();
    expect(screen.getByText(/CTR\s*-32%|CTR\s*−32%/)).toBeInTheDocument();
    expect(screen.getByText('2 / 5')).toBeInTheDocument();
    expect(screen.getByText(/Eid campaign/)).toBeInTheDocument();
  });

  it('leaves ROAS and frequency out when there is none, and writes a dash for a figure not reported', async () => {
    await renderWithProviders(<CreativeSlide section={section} row={row()} n={1} of={1} />);

    expect(screen.queryByText('ROAS')).not.toBeInTheDocument();
    expect(screen.queryByText('Frequency')).not.toBeInTheDocument();
    expect(screen.getByText('CPA')).toBeInTheDocument();
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });

  it('says there is no preview rather than drawing one', async () => {
    await renderWithProviders(
      <CreativeSlide
        section={section}
        row={row({ media: { state: 'fixture', kind: 'none', url: null, origin: null } })}
        n={1}
        of={1}
      />,
    );

    expect(screen.getByText('Sample data — no preview')).toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
  });
});
