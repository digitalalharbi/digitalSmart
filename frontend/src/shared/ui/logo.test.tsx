import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Logo, LOGO_MINIMUM } from './logo';

describe('Logo', () => {
  it('shows the approved artwork as an image, named for assistive technology', () => {
    render(<Logo variant="dark" layout="horizontal" />);

    const logo = screen.getByRole('img', { name: 'digitalSmart' });
    expect(logo.tagName).toBe('IMG');
    expect(logo).toHaveAttribute('data-logo', 'horizontal-dark');
  });

  it('renders both the dark and the light artwork for auto, so a theme change never flashes', () => {
    const { container } = render(<Logo layout="stacked" />);

    expect(container.querySelector('.ds-logo-for-dark')).not.toBeNull();
    expect(container.querySelector('.ds-logo-for-light')).not.toBeNull();
  });

  it('raises a request below the brand minimum instead of shrinking the artwork', () => {
    render(<Logo variant="dark" layout="horizontal" width={40} />);
    expect(screen.getByRole('img', { name: 'digitalSmart' })).toHaveStyle({
      width: `${LOGO_MINIMUM.horizontal}px`,
    });

    render(<Logo variant="dark" layout="mark" width={8} label="mark" />);
    expect(screen.getByRole('img', { name: 'mark' })).toHaveStyle({
      width: `${LOGO_MINIMUM.mark}px`,
    });
  });

  it('can be silent when text beside it already names the product', () => {
    const { container } = render(<Logo variant="mono" layout="mark" label={null} />);
    expect(container.querySelector('img')).toHaveAttribute('alt', '');
  });
});
