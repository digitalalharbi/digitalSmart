import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { ReportSectionView } from './ReportSectionView';
import type { RenderedSection } from './types';

function section(rows: Record<string, string | number | null>[], empty = false): RenderedSection {
  return {
    kind: 'tracking',
    title: 'Funnel',
    metrics: [],
    dimension: null,
    dimension_label: null,
    source: 'live',
    source_label: 'Live',
    requires_label: false,
    is_empty: empty,
    caveats: ['A step no page reported is shown as not reported.'],
    currency: null,
    totals: null,
    series: [],
    rows: [],
    tracking: {
      view: 'funnel',
      columns: [
        { key: 'step', label: 'Step' },
        { key: 'count', label: 'Count' },
        { key: 'rate', label: 'From the previous step (%)' },
      ],
      rows,
    },
  };
}

describe('TrackingSectionView', () => {
  it('writes a step nobody reported as not reported, never as zero', async () => {
    await renderWithProviders(
      <ReportSectionView
        section={section([
          { step: 'Link click', count: 12, rate: null },
          { step: 'Landing view', count: null, rate: null },
        ])}
      />,
    );

    const row = (await screen.findByText('Landing view')).closest('tr');

    expect(row).not.toBeNull();
    expect(within(row as HTMLElement).queryByText('0')).not.toBeInTheDocument();
    expect(within(row as HTMLElement).getAllByText('—').length).toBeGreaterThan(0);
    expect(screen.getByText('12')).toBeInTheDocument();
  });

  it('says so when there is nothing recorded', async () => {
    await renderWithProviders(<ReportSectionView section={section([], true)} />);

    expect(await screen.findByText('Nothing recorded')).toBeInTheDocument();
  });
});
