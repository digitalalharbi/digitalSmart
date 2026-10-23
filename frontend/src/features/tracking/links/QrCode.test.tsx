import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { QrCode } from './QrCode';

describe('QrCode', () => {
  it('draws the short address as a downloadable black-on-white image, locally', async () => {
    await renderWithProviders(
      <QrCode address="https://app.example.com/api/r/AbCdEfGhIj" name="Spring poster" />,
    );

    const image = await screen.findByRole('img', { name: /Spring poster/ });
    const src = image.getAttribute('src') ?? '';
    const svg = decodeURIComponent(src.replace('data:image/svg+xml;charset=utf-8,', ''));

    expect(src.startsWith('data:image/svg+xml')).toBe(true);
    expect(svg).toContain('fill="#fff"');
    expect(svg).toContain('fill="#000"');
    expect(svg).not.toContain('AbCdEfGhIj');

    const download = screen.getByRole('link', { name: 'Download SVG' });

    expect(download).toHaveAttribute('download', 'Spring-poster-qr.svg');
  });

  it('draws a different code for a different address', async () => {
    const { unmount } = await renderWithProviders(
      <QrCode address="https://a.example.com/r/1" name="A" />,
    );
    const first = (await screen.findByRole('img')).getAttribute('src');

    unmount();
    await renderWithProviders(<QrCode address="https://a.example.com/r/2" name="A" />);

    expect((await screen.findByRole('img')).getAttribute('src')).not.toBe(first);
  });
});
