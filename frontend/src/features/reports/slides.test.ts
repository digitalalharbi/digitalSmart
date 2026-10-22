import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { buildSlides, stepFor, swipeStep } from './slides';
import type {
  CreativeSectionRow,
  CreativeViewValue,
  RenderedReport,
  RenderedSection,
} from './types';

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

function creativeRow(position: number): CreativeSectionRow {
  return {
    position,
    label: `Creative ${String(position)}`,
    platform: 'meta',
    platform_label: 'Meta',
    format: 'video',
    format_label: 'Video',
    media: { state: 'none', kind: 'none', url: null, origin: null },
    ads: 1,
    campaigns: [],
    campaign_count: 0,
    metrics: { values: {}, source: 'synced', currency: 'SAR', row_count: 1, is_empty: false },
    trend: [],
    verdict: 'top',
    verdict_label: 'Top',
    evidence: [],
  };
}

function creativeSection(view: CreativeViewValue, count: number): RenderedSection {
  return section({
    kind: 'creative',
    title: 'Creatives',
    metrics: [],
    totals: null,
    creative: {
      view,
      total: count,
      mix_is_spend: true,
      mix: [],
      rows: Array.from({ length: count }, (_, index) => creativeRow(index + 1)),
    },
  });
}

describe('creative screens', () => {
  it('tells the refresh story in order: tiring first, failed last', () => {
    const section = creativeSection('refresh', 3);
    section.creative!.rows[0]!.verdict = 'weak';
    section.creative!.rows[1]!.verdict = 'fatiguing';
    section.creative!.rows[2]!.verdict = 'weak';

    const verdicts = buildSlides(report([section]))
      .filter((slide) => slide.kind === 'creative')
      .map((slide) => slide.row.verdict);

    expect(verdicts).toEqual(['fatiguing', 'weak', 'weak']);
  });

  it('gives each top and each to-refresh creative its own screen, in order', () => {
    const slides = buildSlides(report([creativeSection('top', 3), creativeSection('refresh', 2)]));
    const creatives = slides.filter((slide) => slide.kind === 'creative');

    expect(creatives).toHaveLength(5);
    expect(creatives[0]).toMatchObject({ n: 1, of: 3 });
    expect(creatives[3]).toMatchObject({ n: 1, of: 2 });
  });

  it('keeps a table, a mix and a comparison as one screen each, and skips a creative section with nothing', () => {
    const slides = buildSlides(
      report([
        creativeSection('performance', 4),
        creativeSection('format_mix', 4),
        creativeSection('comparison', 4),
        { ...creativeSection('top', 0), is_empty: true },
      ]),
    );

    expect(slides.filter((slide) => slide.kind === 'creative')).toHaveLength(0);
    expect(slides.filter((slide) => slide.kind === 'section')).toHaveLength(3);
  });
});

describe('the creative story', () => {
  it('opens on the summary, runs top → comparison → refresh → mixes, and closes on the advice, whatever the saved order', () => {
    const winner = { ...creativeRow(8), label: 'Winner' };
    winner.metrics = {
      values: { roas: value('roas', 9), cpa: value('cpa', 10) },
      source: 'synced',
      currency: 'SAR',
      row_count: 1,
      is_empty: false,
    };
    const other = { ...creativeRow(9), label: 'Runner-up' };
    other.metrics = {
      values: { roas: value('roas', 2) },
      source: 'synced',
      currency: 'SAR',
      row_count: 1,
      is_empty: false,
    };
    const gallery = {
      ...creativeSection('gallery', 0),
      creative: {
        view: 'gallery' as const,
        total: 2,
        mix_is_spend: true,
        mix: [],
        rows: [winner, other],
      },
      is_empty: false,
    };

    const slides = buildSlides(
      report([
        creativeSection('format_mix', 2),
        creativeSection('refresh', 1),
        creativeSection('top', 1),
        gallery,
        creativeSection('comparison', 2),
      ]),
    );
    const kinds = slides.map((slide) =>
      slide.kind === 'section'
        ? slide.section.creative?.view
        : slide.kind === 'creative'
          ? `creative:${slide.section.creative?.view ?? ''}`
          : slide.kind,
    );

    expect(kinds).toEqual([
      'cover',
      'creative-summary',
      'gallery',
      'creative:top',
      'comparison',
      'creative:refresh',
      'format_mix',
      'creative-advice',
      'status',
    ]);
  });
});
