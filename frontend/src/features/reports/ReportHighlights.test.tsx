import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import type { MetricValue } from '@/shared/metrics';
import { ReportHighlights } from './ReportHighlights';
import type { RenderedReport, RenderedSection } from './types';

function value(
  metric: string,
  amount: number | null,
  over: Partial<MetricValue> = {},
): MetricValue {
  return {
    metric,
    label: metric.toUpperCase(),
    value: amount,
    format: 'count',
    precision: 1,
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
    ...over,
  };
}

const set = (values: Record<string, MetricValue>) => ({
  values,
  source: 'synced',
  currency: null,
  row_count: 1,
  is_empty: false,
});

function section(over: Partial<RenderedSection>): RenderedSection {
  return {
    kind: 'scorecard',
    title: 't',
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
    ...over,
  };
}

const row = (label: string, spend: number, roas: number) => ({
  key: label,
  label,
  status: null,
  metrics: set({ spend: value('spend', spend), roas: value('roas', roas) }),
});

const full = {
  sections: [
    section({
      totals: set({
        roas: value('roas', 4, { change_ratio: 0.21, change_is_good: true }),
        ctr: value('ctr', 1.1, { change_ratio: -0.19, change_is_good: false }),
      }),
    }),
    section({
      kind: 'breakdown',
      dimension: 'campaign',
      metrics: ['spend', 'roas'],
      rows: [row('Alpha', 90, 5), row('Beta', 80, 4), row('Gamma', 70, 4), row('Delta', 10, 1)],
    }),
    section({
      kind: 'breakdown',
      dimension: 'provider',
      metrics: ['spend', 'roas'],
      rows: [row('Meta', 90, 5.2), row('Google', 80, 2.5)],
    }),
  ],
} as unknown as RenderedReport;

describe('ReportHighlights', () => {
  it('shows what changed, who carried it, who lagged, and a pointer — each with its figures', async () => {
    await renderWithProviders(<ReportHighlights report={full} />);

    const changes = screen.getByRole('list', { name: 'What changed' });
    expect(within(changes).getByText('Improved')).toBeInTheDocument();
    expect(within(changes).getByText('Needs attention')).toBeInTheDocument();
    expect(within(changes).getByText('Best performer')).toBeInTheDocument();

    expect(screen.getByRole('heading', { name: 'Top campaigns' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Needs attention' })).toBeInTheDocument();
    expect(screen.getByText('Delta')).toBeInTheDocument();

    const advice = screen.getByRole('list', { name: 'Worth a closer look' });
    expect(within(advice).getByText('Consider more budget on Meta')).toBeInTheDocument();
    expect(within(advice).getByText('Refresh the creatives')).toBeInTheDocument();
    expect(within(advice).getAllByText('Evidence').length).toBe(2);
  });

  it('offers the working only when asked to, and says nothing when there is nothing to say', async () => {
    const onViewDetails = vi.fn();
    const user = userEvent.setup();
    const { container, rerender } = await renderWithProviders(
      <ReportHighlights report={full} onViewDetails={onViewDetails} />,
    );

    await user.click(screen.getByRole('button', { name: /View details/ }));
    expect(onViewDetails).toHaveBeenCalledOnce();

    rerender(<ReportHighlights report={{ sections: [] } as unknown as RenderedReport} />);
    expect(container).toBeEmptyDOMElement();
  });
});
