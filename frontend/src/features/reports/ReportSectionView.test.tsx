import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import type { MetricValue } from '@/shared/metrics';
import { ReportSectionView } from './ReportSectionView';
import type { RenderedSection } from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

function value(metric: string, amount: number | null): MetricValue {
  return {
    metric,
    label: metric,
    value: amount,
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
  };
}

function set(metric: string, amount: number | null) {
  return {
    values: { [metric]: value(metric, amount) },
    source: 'synced',
    currency: null,
    row_count: 1,
    is_empty: false,
  };
}

function breakdown(metric: string, rows: Array<[string, number | null]>): RenderedSection {
  return {
    kind: 'breakdown',
    title: 'By platform',
    metrics: [metric],
    dimension: 'provider',
    dimension_label: 'Platform',
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    caveats: [],
    currency: null,
    totals: null,
    series: [],
    rows: rows.map(([label, amount]) => ({
      key: label,
      label,
      status: null,
      metrics: set(metric, amount),
    })),
  };
}

describe('ReportSectionView — platform share', () => {
  it('draws a ring for a figure that adds up across platforms', async () => {
    const { container } = await renderWithProviders(
      <ReportSectionView
        section={breakdown('clicks', [
          ['Meta', 60],
          ['Google', 40],
        ])}
      />,
    );

    expect(container.querySelectorAll('circle').length).toBeGreaterThan(1);
  });

  it('draws no ring for reach, which is not a share of anything', async () => {
    const { container } = await renderWithProviders(
      <ReportSectionView
        section={breakdown('reach', [
          ['Meta', 60],
          ['Google', 40],
        ])}
      />,
    );

    expect(container.querySelectorAll('circle')).toHaveLength(0);
  });

  it('draws no ring when only one platform reported', async () => {
    const { container } = await renderWithProviders(
      <ReportSectionView
        section={breakdown('clicks', [
          ['Meta', 60],
          ['Google', null],
        ])}
      />,
    );

    expect(container.querySelectorAll('circle')).toHaveLength(0);
  });
});
