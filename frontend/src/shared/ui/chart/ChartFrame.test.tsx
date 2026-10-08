import { beforeEach, describe, expect, it } from 'vitest';
import { act, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { useUiStore } from '@/shared/store/ui-store';
import { ChartFrame } from './ChartFrame';
import { useTextureChannel } from './texture';

function frame() {
  return (
    <ChartFrame
      title="Spend by channel"
      table={{
        columns: ['Channel', 'Spend'],
        rows: [{ key: 'meta', label: 'Meta', cells: ['12,000'] }],
      }}
    >
      <div data-testid="chart" />
    </ChartFrame>
  );
}

beforeEach(() => {
  useUiStore.setState({ chartTexture: 'auto' });
});

describe('the chart frame’s pattern toggle', () => {
  /*
   * Offered where the problem is noticed. A reader who cannot tell two series apart is looking at a
   * chart, not at a settings page — and there is no media query for full colour blindness.
   */
  it('is beside the chart, pressed state and all', async () => {
    await renderWithProviders(frame());

    const toggle = screen.getByRole('button', { name: 'Patterns' });

    expect(toggle).toHaveAttribute('aria-pressed', 'false');

    await userEvent.click(toggle);

    expect(toggle).toHaveAttribute('aria-pressed', 'true');
    expect(useUiStore.getState().chartTexture).toBe('always');
  });

  /*
   * One preference for the whole product, not one per chart. Somebody who needs patterns needs them
   * everywhere, and a per-chart switch would mean setting it nine times on a dashboard.
   */
  it('says in its own words that it applies to every chart', async () => {
    await renderWithProviders(frame());

    expect(screen.getByRole('button', { name: 'Patterns' })).toHaveAttribute(
      'title',
      expect.stringContaining('every chart'),
    );
  });

  it('still offers the table, which is the channel that needs no colour at all', async () => {
    await renderWithProviders(frame());

    await userEvent.click(screen.getByRole('button', { name: 'Table' }));

    expect(screen.getByRole('table', { name: /Spend by channel/ })).toBeInTheDocument();
  });
});

describe('when the channel turns itself on', () => {
  it('follows the reader’s stored preference', () => {
    const { result, rerender } = renderHook(() => useTextureChannel());

    expect(result.current).toBe(false);

    act(() => {
      useUiStore.setState({ chartTexture: 'always' });
    });
    rerender();

    expect(result.current).toBe(true);
  });

  /*
   * A canvas prints whatever was last drawn on it, so the patterns have to be there before the
   * print dialog opens. Without this, "it works on screen" and "it works on paper" are different
   * answers — and the paper one is the copy a client keeps.
   */
  it('turns on while a print is in progress, and off again afterwards', () => {
    const { result, rerender } = renderHook(() => useTextureChannel());

    expect(result.current).toBe(false);

    act(() => {
      window.dispatchEvent(new Event('beforeprint'));
    });
    rerender();

    expect(result.current).toBe(true);

    act(() => {
      window.dispatchEvent(new Event('afterprint'));
    });
    rerender();

    expect(result.current).toBe(false);
  });

  /*
   * React's strict mode mounts, unmounts and mounts again, so the listeners are added twice and
   * removed once in between. What matters is that the answer is right afterwards, not how many
   * times the effect ran.
   */
  it('is unaffected by an effect running twice', () => {
    const first = renderHook(() => useTextureChannel());
    first.unmount();

    const second = renderHook(() => useTextureChannel());

    act(() => {
      window.dispatchEvent(new Event('beforeprint'));
    });
    second.rerender();

    expect(second.result.current).toBe(true);
  });
});
