import { describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { FunnelView } from './FunnelView';
import { formatDuration, periodOfDays } from './duration';
import type { FunnelAnalytics, StageResult } from './types';

function stage(overrides: Partial<StageResult>): StageResult {
  return {
    source: 'link_click',
    label_ar: null,
    label_en: null,
    required: true,
    unit: 'session',
    count: 10,
    state: 'live',
    reason: null,
    conversion_from_previous: null,
    conversion_reason: null,
    drop_off: null,
    drop_off_rate: null,
    median_seconds_from_previous: null,
    ...overrides,
  };
}

const FUNNEL: FunnelAnalytics = {
  name: null,
  is_standard: true,
  overall_conversion: 0.1,
  overall_reason: null,
  truncated: false,
  excluded_sessions: 2,
  from: '2026-10-01',
  to: '2026-10-30',
  stages: [
    stage({}),
    stage({
      source: 'landing_view',
      count: null,
      state: 'partial',
      reason: 'The destination page has not reported this step.',
    }),
    stage({
      source: 'form_submit',
      unit: 'submission',
      count: 4,
      conversion_from_previous: null,
      conversion_reason: 'The step before it is not being reported.',
    }),
    stage({
      source: 'lead_created',
      unit: 'lead',
      count: 1,
      conversion_from_previous: 0.25,
      drop_off: 3,
      median_seconds_from_previous: 120,
    }),
  ],
};

describe('FunnelView', () => {
  it('shows a step nobody reported as not reported with its reason, never as zero', async () => {
    await renderWithProviders(<FunnelView funnel={FUNNEL} />);

    const rows = screen.getAllByRole('listitem');
    const landing = rows[1];

    expect(landing).toBeDefined();
    expect(
      within(landing as HTMLElement).getByText('The destination page has not reported this step.'),
    ).toBeInTheDocument();
    expect(within(landing as HTMLElement).getByText('Not reported')).toBeInTheDocument();
    expect(within(landing as HTMLElement).queryByText('0')).not.toBeInTheDocument();
  });

  it('gives the rate, the drop and the wait where they mean something, and a reason where they do not', async () => {
    await renderWithProviders(<FunnelView funnel={FUNNEL} />);

    const lead = screen.getAllByRole('listitem')[3] as HTMLElement;
    expect(within(lead).getByText('25.0%')).toBeInTheDocument();
    expect(within(lead).getByText('3')).toBeInTheDocument();
    expect(within(lead).getByText('2m')).toBeInTheDocument();
    expect(screen.getByText('The step before it is not being reported.')).toBeInTheDocument();
    expect(screen.getByText(/Overall: 10\.0% reach the last step/)).toBeInTheDocument();
    expect(screen.getByText(/2 visits were marked as likely automated/)).toBeInTheDocument();
  });
});

describe('duration helpers', () => {
  it('phrases a wait in the largest sensible unit, with Latin digits in Arabic', () => {
    expect(formatDuration(45, 'en')).toBe('45s');
    expect(formatDuration(600, 'en')).toBe('10m');
    expect(formatDuration(7200, 'en')).toBe('2h');
    expect(formatDuration(3 * 86_400, 'en')).toBe('3d');
    expect(formatDuration(600, 'ar')).toMatch(/10/);
  });

  it('counts days back inclusively from today', () => {
    expect(periodOfDays(7, new Date('2026-10-23T12:00:00Z'))).toEqual({
      from: '2026-10-17',
      to: '2026-10-23',
    });
  });
});
