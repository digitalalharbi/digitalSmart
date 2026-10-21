import { fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { ReportView } from './ReportView';
import type { RenderedReport } from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

const figure = (metric: string, value: number) => ({
  metric,
  label: metric,
  value,
  format: 'count' as const,
  precision: 0,
  is_minor_units: false,
  currency: null,
  source: 'synced' as const,
  source_label: 'Synced',
  requires_label: false,
  unavailable_reason: null,
  comparison_value: null,
  change_ratio: null,
  change_is_good: null,
  lower_is_better: false,
});

const report: RenderedReport = {
  id: 'r',
  name: 'Monthly',
  description: null,
  client_name: 'Alhambra',
  period: { from: '2026-09-01', until: '2026-09-30', days: 30, is_incomplete: false },
  comparison: {
    mode: 'none',
    label: 'None',
    from: null,
    until: null,
    was_requested: false,
    is_comparable: false,
    reason: null,
    code: null,
  },
  audience: 'client_safe',
  mode: 'live',
  mode_label: 'Live',
  source: 'synced',
  source_label: 'Synced',
  requires_label: false,
  is_empty: false,
  internal_note: null,
  rendered_at: '2026-10-01T09:00:00Z',
  sections: [
    {
      kind: 'scorecard',
      title: 'Headline',
      metrics: ['impressions', 'clicks', 'conversions'],
      dimension: null,
      dimension_label: null,
      source: 'synced',
      source_label: 'Synced',
      requires_label: false,
      is_empty: false,
      caveats: ['Figures for the last day may still change.'],
      currency: null,
      totals: {
        values: {
          impressions: figure('impressions', 10_000),
          clicks: figure('clicks', 400),
          conversions: figure('conversions', 12),
        },
        source: 'synced',
        currency: null,
        row_count: 3,
        is_empty: false,
      },
      series: [],
      rows: [],
    },
  ],
};

async function open() {
  await renderWithProviders(<ReportView report={report} presentable />);
  await userEvent.click(await screen.findByRole('button', { name: 'Present' }));

  return screen.findByRole('dialog', { name: /Monthly — presentation/ });
}

describe('ReportPresentation', () => {
  it('is offered only where asked for', async () => {
    await renderWithProviders(<ReportView report={report} />);

    expect(screen.queryByRole('button', { name: 'Present' })).not.toBeInTheDocument();
  });

  it('opens on the cover as a modal dialog, with the page behind it held still', async () => {
    const deck = await open();

    expect(deck).toHaveAttribute('aria-modal', 'true');
    expect(within(deck).getByRole('heading', { level: 2, name: 'Monthly' })).toBeInTheDocument();
    expect(document.body.style.overflow).toBe('hidden');
  });

  it('moves through the deck by keyboard and by its own buttons, and cannot run off either end', async () => {
    const deck = await open();

    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();

    fireEvent.keyDown(deck, { key: 'ArrowRight' });
    expect(
      await within(deck).findByRole('heading', { level: 2, name: 'Headline' }),
    ).toBeInTheDocument();

    fireEvent.keyDown(deck, { key: 'End' });
    expect(
      await within(deck).findByRole('heading', { level: 2, name: 'Data status' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();

    await userEvent.click(screen.getByRole('button', { name: 'Previous' }));
    expect(
      await within(deck).findByRole('heading', { level: 2, name: 'Where the audience went' }),
    ).toBeInTheDocument();

    fireEvent.keyDown(deck, { key: 'Home' });
    expect(
      await within(deck).findByRole('heading', { level: 2, name: 'Monthly' }),
    ).toBeInTheDocument();
  });

  it('shows the headline figures large, one per tile', async () => {
    const deck = await open();
    fireEvent.keyDown(deck, { key: 'ArrowRight' });

    // Short on the screen (10K), whole in its title: read from across a room, checked up close.
    expect(await within(deck).findByText('10K')).toHaveAttribute('title', '10,000');
    expect(within(deck).getByText('400')).toBeInTheDocument();
  });

  it('ends on the data status, with the caveats and the source', async () => {
    const deck = await open();
    fireEvent.keyDown(deck, { key: 'End' });

    expect(
      await within(deck).findByText('Figures for the last day may still change.'),
    ).toBeInTheDocument();
  });

  it('closes on Escape and on its button, and gives the page back', async () => {
    const deck = await open();

    fireEvent.keyDown(deck, { key: 'Escape' });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(document.body.style.overflow).not.toBe('hidden');
  });
});
