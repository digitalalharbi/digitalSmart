import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { renderWithProviders } from '@/test/render';
import { ReportSectionView } from './ReportSectionView';
import type { AttributionSectionData, RenderedSection } from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

function value(
  metric: string,
  amount: number | null,
  format: MetricValue['format'] = 'count',
): MetricValue {
  return {
    metric,
    label: metric,
    value: amount,
    format,
    precision: 0,
    is_minor_units: false,
    currency: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    unavailable_reason: amount === null ? 'Not reported' : null,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
  };
}

function base(overrides: Partial<RenderedSection>): RenderedSection {
  return {
    kind: 'traffic',
    title: 'Clicks and funnel',
    metrics: [],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    caveats: [],
    currency: null,
    totals: null,
    series: [],
    rows: [],
    ...overrides,
  };
}

describe('the clicks and funnel section', () => {
  it('draws the stages in order and says which one was not tracked, instead of drawing it short', async () => {
    const traffic = base({
      totals: {
        source: 'synced',
        currency: null,
        values: {
          impressions: value('impressions', 100_000),
          clicks: value('clicks', 2_500),
          link_clicks: value('link_clicks', null),
          conversions: value('conversions', 80),
          ctr: value('ctr', 2.5, 'percentage'),
        },
      } as unknown as RenderedSection['totals'],
    });

    await renderWithProviders(<ReportSectionView section={traffic} />);

    expect(await screen.findByText('Seen')).toBeInTheDocument();
    expect(screen.getByText('Clicked')).toBeInTheDocument();
    expect(screen.getByText('Results')).toBeInTheDocument();
    // The unreported stage is named as untracked, in the funnel and not as a zero.
    expect(screen.getAllByText('Not tracked').length).toBeGreaterThan(0);
  });
});

const attribution: AttributionSectionData = {
  model: 'last_recorded',
  model_label: 'Last recorded',
  is_modelled: false,
  revenue_source: 'salla',
  revenue_source_label: 'Salla',
  lookback_days: 30,
  coverage: {
    conversions: 40,
    attributed_conversions: 20,
    campaign_named_conversions: 12,
    conversion_coverage: 50,
    revenue_coverage: 42.5,
    campaign_named_share: 30,
    unattributed_revenue_minor: 120_000,
    is_reportable: false,
    reportable_percent: 70,
  },
  unmatched_spend_minor: null,
  spend_currency: 'SAR',
  rows: [
    {
      label: 'Ramadan sale',
      provider: 'meta',
      link: 'exact_name',
      conversions: 12,
      revenue_minor: 500_000,
      currency: 'SAR',
      evidence: 'campaign_parameter',
      spend_minor: 100_000,
      roas: 5,
    },
    {
      label: 'Coupon RAMADAN',
      provider: null,
      link: 'none',
      conversions: 3,
      revenue_minor: null,
      currency: 'SAR',
      evidence: 'coupon',
      spend_minor: null,
      roas: null,
    },
  ],
};

describe('the attributed revenue section', () => {
  it('leads with how much of the period it explains, and says to treat the rows as a sample when it is under the line', async () => {
    await renderWithProviders(
      <ReportSectionView
        section={base({ kind: 'attribution', title: 'Attributed revenue', attribution })}
      />,
    );

    expect(await screen.findByText('Treat as a sample')).toBeInTheDocument();
    expect(screen.getByText('42.5%')).toBeInTheDocument();
    expect(screen.getByText('Ramadan sale')).toBeInTheDocument();
  });

  it('writes a revenue it does not have as not available, and a return it cannot compute the same way', async () => {
    await renderWithProviders(
      <ReportSectionView
        section={base({ kind: 'attribution', title: 'Attributed revenue', attribution })}
      />,
    );
    await screen.findByText('Coupon RAMADAN');

    const row = screen.getByText('Coupon RAMADAN').closest('tr');
    expect(row).not.toBeNull();
    expect(row?.textContent).not.toMatch(/0\.00/);
  });
});
