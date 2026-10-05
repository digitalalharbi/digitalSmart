import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { MetricCard } from './metric-card';
import type { MetricValue } from '@/shared/metrics';

function value(overrides: Partial<MetricValue> = {}): MetricValue {
  return {
    metric: 'spend',
    label: 'Spend',
    value: 250_000,
    format: 'currency',
    precision: 2,
    is_minor_units: true,
    currency: 'SAR',
    source: 'live',
    source_label: 'Live',
    requires_label: false,
    unavailable_reason: null,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
    ...overrides,
  };
}

describe('MetricCard', () => {
  it('renders the figure with its label', async () => {
    await renderWithProviders(<MetricCard value={value()} />);

    expect(screen.getByText('Spend')).toBeInTheDocument();
    expect(screen.getByText(/2,500\.00/)).toBeInTheDocument();
  });

  it('shows why a figure is missing, instead of only a dash', async () => {
    await renderWithProviders(
      <MetricCard
        value={value({
          value: null,
          unavailable_reason:
            'Reach is deduplicated by the platform, so it cannot be added across 4 entities.',
        })}
      />,
    );

    /*
     * A reader who sees a dash and no explanation assumes the product is broken.
     * One who sees the reason knows it is working and the platform is the limit —
     * and knows what to filter to in order to see the figure.
     */
    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.getByText(/deduplicated by the platform/)).toBeInTheDocument();
  });

  it('colours a change by whether it is good, not by its sign', async () => {
    // CPA falling is good news. The server decides this from the metric's
    // direction; the card must not re-derive it from the sign.
    const { container } = await renderWithProviders(
      <MetricCard
        value={value({
          metric: 'cpa',
          label: 'CPA',
          change_ratio: -0.2,
          change_is_good: true,
          lower_is_better: true,
        })}
      />,
    );

    expect(container.querySelector('.text-success')).not.toBeNull();
    expect(container.querySelector('.text-danger')).toBeNull();
  });

  it('marks a sandbox figure visibly', async () => {
    await renderWithProviders(
      <MetricCard value={value({ source: 'sandbox', requires_label: true })} />,
    );

    // A sandbox figure mistaken for a client's spend is the failure the whole
    // source mechanism exists to prevent.
    expect(screen.getByText('Sandbox')).toBeInTheDocument();
  });

  it('shows no badge for a live figure, because the absence is the statement', async () => {
    await renderWithProviders(<MetricCard value={value({ source: 'live' })} />);

    expect(screen.queryByText('Live')).not.toBeInTheDocument();
  });

  it('says plainly when there is nothing to compare against', async () => {
    await renderWithProviders(<MetricCard value={value({ change_ratio: null })} />);

    expect(screen.getByText('No comparison')).toBeInTheDocument();
  });

  it('becomes a button when it can be selected', async () => {
    const onSelect = vi.fn();
    await renderWithProviders(
      <MetricCard value={value()} onSelect={onSelect} isSelected={false} />,
    );

    const button = screen.getByRole('button', { pressed: false });
    await userEvent.click(button);

    expect(onSelect).toHaveBeenCalledOnce();
  });
});
