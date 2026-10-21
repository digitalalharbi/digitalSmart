import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { buildSlides, stepFor, swipeStep } from './slides';
import type { RenderedReport, RenderedSection } from './types';

function value(metric: string, amount: number | null, change: number | null = null): MetricValue {
  return {
    metric,
    label: metric,
    value: amount,
    format: 'count',
    precision: 0,
    is_minor_units: false,
    currency: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    unavailable_reason: null,
    comparison_value: change === null ? null : 100,
    change_ratio: change,
    change_is_good: change === null ? null : change > 0,
    lower_is_better: false,
  };
}

function section(overrides: Partial<RenderedSection>): RenderedSection {
  return {
    kind: 'scorecard',
    title: 'Headline',
    metrics: ['impressions', 'clicks', 'conversions'],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: false,
    caveats: [],
    currency: null,
    totals: {
      values: {
        impressions: value('impressions', 10_000, 0.2),
        clicks: value('clicks', 400),
        conversions: value('conversions', 12),
      },
      source: 'synced',
      currency: null,
      row_count: 3,
      is_empty: false,
    },
    series: [],
    rows: [],
    ...overrides,
  };
}

function report(sections: RenderedSection[]): RenderedReport {
  return {
    id: 'r',
    name: 'Monthly',
    description: null,
    client_name: 'Alhambra',
    period: { from: '2026-09-01', until: '2026-09-30', days: 30, is_incomplete: false },
    comparison: {
      mode: 'previous_period',
      label: 'Period before',
      from: null,
      until: null,
      was_requested: true,
      is_comparable: true,
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
    sections,
  };
}

describe('buildSlides', () => {
  it('opens on a cover and closes on the data status, with the stories between', () => {
    const slides = buildSlides(report([section({})]));

    expect(slides[0]?.kind).toBe('cover');
    expect(slides[slides.length - 1]?.kind).toBe('status');
    expect(slides.some((slide) => slide.kind === 'kpis')).toBe(true);
  });

  it('draws a funnel screen only where the figures carry the stages', () => {
    const withFunnel = buildSlides(report([section({})]));
    const without = buildSlides(
      report([
        section({
          metrics: ['clicks'],
          totals: {
            values: { clicks: value('clicks', 400) },
            source: 'synced',
            currency: null,
            row_count: 1,
            is_empty: false,
          },
        }),
      ]),
    );

    expect(withFunnel.some((slide) => slide.kind === 'highlight' && slide.part === 'funnel')).toBe(
      true,
    );
    expect(without.some((slide) => slide.kind === 'highlight' && slide.part === 'funnel')).toBe(
      false,
    );
  });

  it('leaves out a section with nothing in it rather than showing a screen of nothing', () => {
    const slides = buildSlides(report([section({}), section({ kind: 'trend', is_empty: true })]));

    expect(slides.some((slide) => slide.kind === 'section')).toBe(false);
  });

  it('reads headline figures first, then how they moved, then the rankings', () => {
    const slides = buildSlides(
      report([
        section({
          kind: 'breakdown',
          dimension: 'provider',
          rows: [
            {
              key: 'k',
              label: 'Meta',
              status: null,
              metrics: {
                values: {},
                source: 'synced',
                currency: null,
                row_count: 1,
                is_empty: false,
              },
            },
          ],
        }),
        section({
          kind: 'trend',
          series: [
            {
              date: '2026-09-01',
              metrics: {
                values: {},
                source: 'synced',
                currency: null,
                row_count: 1,
                is_empty: false,
              },
            },
          ],
        }),
        section({}),
      ]),
    );
    const kinds = slides.map((slide) =>
      slide.kind === 'section' ? slide.section.kind : slide.kind,
    );

    expect(kinds.indexOf('kpis')).toBeLessThan(kinds.indexOf('trend'));
    expect(kinds.indexOf('trend')).toBeLessThan(kinds.indexOf('breakdown'));
  });
});

describe('stepFor', () => {
  it('moves forward on the right arrow in English and on the left one in Arabic', () => {
    expect(stepFor('ArrowRight', false)).toBe(1);
    expect(stepFor('ArrowLeft', false)).toBe(-1);
    expect(stepFor('ArrowLeft', true)).toBe(1);
    expect(stepFor('ArrowRight', true)).toBe(-1);
  });

  it('moves on page keys and space either way round, and not on anything else', () => {
    expect(stepFor('PageDown', true)).toBe(1);
    expect(stepFor('PageUp', false)).toBe(-1);
    expect(stepFor(' ', false)).toBe(1);
    expect(stepFor('a', false)).toBe(0);
  });
});

describe('swipeStep', () => {
  it('goes forward on a leftward swipe in English and a rightward one in Arabic', () => {
    expect(swipeStep(-120, 5, false)).toBe(1);
    expect(swipeStep(120, 5, false)).toBe(-1);
    expect(swipeStep(120, 5, true)).toBe(1);
  });

  it('ignores a tap, a short drag and a vertical scroll', () => {
    expect(swipeStep(3, 2, false)).toBe(0);
    expect(swipeStep(40, 0, false)).toBe(0);
    expect(swipeStep(80, 120, false)).toBe(0);
  });
});
