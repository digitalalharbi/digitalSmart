import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { FunnelBars } from './FunnelBars';

const stages = [
  { key: 'seen', label: 'Impressions', value: 10_000, display: '10K' },
  { key: 'clicked', label: 'Clicks', value: 200, display: '200' },
  {
    key: 'carted',
    label: 'Add to cart',
    value: 60,
    display: '60',
    change: { text: '+12%', good: true },
  },
  {
    key: 'bought',
    label: 'Purchases',
    value: 6,
    display: '6',
    change: { text: '-4%', good: false },
  },
];

describe('FunnelBars', () => {
  it('marks the biggest drop after the click, and not the click-through rate', () => {
    render(
      <FunnelBars
        label="Funnel"
        notTracked="Not tracked"
        stages={stages}
        leakFrom={2}
        leakLabel="Biggest drop"
      />,
    );

    const marked = screen.getAllByText('Biggest drop');

    expect(marked).toHaveLength(1);
    // Purchases (10% of add-to-cart) is lower than add-to-cart (30% of clicks).
    expect(marked[0]?.closest('li')).toHaveTextContent('Purchases');
  });

  it('marks nothing without a label, so a funnel that is not asked to judge does not', () => {
    render(<FunnelBars label="Funnel" notTracked="Not tracked" stages={stages} />);

    expect(screen.queryByText('Biggest drop')).not.toBeInTheDocument();
  });

  it('writes the movement against the earlier period beside the stage', () => {
    render(<FunnelBars label="Funnel" notTracked="Not tracked" stages={stages} />);

    expect(screen.getByText('+12%')).toBeInTheDocument();
    expect(screen.getByText('-4%')).toBeInTheDocument();
  });

  it('draws a stage nobody measured as not tracked, with no bar and no rate', () => {
    render(
      <FunnelBars
        label="Funnel"
        notTracked="Not tracked"
        stages={[
          { key: 'a', label: 'Impressions', value: 1000, display: '1K' },
          { key: 'b', label: 'Clicks', value: null, display: '—' },
        ]}
      />,
    );

    expect(screen.getAllByText('Not tracked').length).toBeGreaterThan(0);
    expect(screen.queryByText('0%')).not.toBeInTheDocument();
  });
});
