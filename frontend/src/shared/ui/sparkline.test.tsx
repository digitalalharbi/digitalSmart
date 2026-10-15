import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { Sparkline } from './sparkline';

describe('Sparkline', () => {
  it('breaks the line at an unreported day instead of bridging or zeroing it', () => {
    const { container } = render(<Sparkline values={[1, 2, null, 3, 4]} />);

    expect(container.querySelectorAll('path')).toHaveLength(2);
  });

  it('draws nothing it cannot support from fewer than two figures', () => {
    const { container } = render(<Sparkline values={[null, 5, null]} />);

    expect(container.querySelector('svg')).toBeNull();
  });

  it('is decorative unless it is named', () => {
    const { container, rerender } = render(<Sparkline values={[1, 2, 3]} />);
    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');

    rerender(<Sparkline values={[1, 2, 3]} label="Spend" />);
    expect(container.querySelector('svg')).toHaveAttribute('role', 'img');
  });
});
