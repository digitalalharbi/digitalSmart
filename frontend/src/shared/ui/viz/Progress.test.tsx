import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { CompareBars, ProgressBar, ProgressRing } from './Progress';

describe('Progress', () => {
  it('is decorative until it is given a name, then a named progress bar with its value', () => {
    const { container, rerender } = render(<ProgressBar value={30} />);
    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true');

    rerender(<ProgressBar value={30} label="Budget spent" />);
    expect(screen.getByRole('progressbar', { name: 'Budget spent' })).toHaveAttribute(
      'aria-valuenow',
      '30',
    );
  });

  it('never draws past its ends, however far the figure runs over', () => {
    render(<ProgressBar value={250} max={100} label="Over" />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    expect(screen.getByRole('progressbar').firstElementChild).toHaveStyle({ width: '100%' });
  });

  it('a ring says its share in words for a screen reader and prints it in the middle', () => {
    render(<ProgressRing value={42} label="Setup" />);
    expect(screen.getByRole('img', { name: 'Setup: 42%' })).toBeInTheDocument();
    expect(screen.getByText('42%')).toBeInTheDocument();
  });

  it('compares before and after on one scale and says how much it moved', () => {
    render(
      <CompareBars
        from={1000}
        to={800}
        fromLabel="Now"
        toLabel="Proposed"
        format={(n) => `SAR ${String(n)}`}
      />,
    );
    expect(screen.getByText('SAR 1000')).toBeInTheDocument();
    expect(screen.getByText('SAR 800')).toBeInTheDocument();
    expect(screen.getByText('−20%')).toBeInTheDocument();
  });

  it('has no percentage to claim when it starts from nothing', () => {
    render(<CompareBars from={0} to={500} fromLabel="Now" toLabel="Proposed" format={String} />);
    expect(screen.queryByText(/%/)).not.toBeInTheDocument();
  });
});
