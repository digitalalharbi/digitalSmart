import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Sparkline } from './Sparkline';

describe('Sparkline', () => {
  it('draws one line for an unbroken series', () => {
    const { container } = render(<Sparkline values={[1, 3, 2, 5]} />);

    expect(container.querySelectorAll('polyline')).toHaveLength(1);
  });

  it('breaks the line at a missing day instead of drawing it as a zero', () => {
    const { container } = render(<Sparkline values={[1, 2, null, 4, 5]} />);

    expect(container.querySelectorAll('polyline')).toHaveLength(2);
  });

  it('draws nothing for fewer than two points: one point is not a movement', () => {
    const { container } = render(<Sparkline values={[null, 4, null]} />);

    expect(container.querySelector('svg')).toBeNull();
  });

  it('is hidden from assistive technology; the figures are in the table twin', () => {
    const { container } = render(<Sparkline values={[1, 2, 3]} />);

    expect(container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
  });
});
