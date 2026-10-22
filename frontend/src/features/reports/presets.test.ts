import { describe, expect, it } from 'vitest';
import { isAvailable, PRESETS, presetSections } from './presets';
import type { ReportVocabulary, SectionKindInfo } from './types';

function kind(
  overrides: Partial<SectionKindInfo> & Pick<SectionKindInfo, 'kind'>,
): SectionKindInfo {
  return {
    label: overrides.kind,
    needs_dimension: false,
    supports_comparison: false,
    metric_cap: 6,
    uses_metrics: true,
    metrics: [
      'spend',
      'impressions',
      'clicks',
      'ctr',
      'conversions',
      'cpa',
      'roas',
      'leads',
      'cpl',
      'purchases',
      'revenue',
    ],
    ...overrides,
  };
}

const vocabulary = {
  windows: [],
  comparisons: [],
  audiences: [],
  metrics: [],
  dimensions: [],
  max_share_days: 90,
  revenue_sources: [
    { source: 'attributed', label: 'Attributed', is_invoiced: false, is_modelled: true },
    { source: 'salla', label: 'Salla', is_invoiced: true, is_modelled: false },
  ],
  sections: [
    kind({ kind: 'scorecard' }),
    kind({ kind: 'trend', metric_cap: 4 }),
    kind({
      kind: 'breakdown',
      needs_dimension: true,
      metric_cap: 3,
      // A ratio is not additive across rows, so the server leaves it out of a ranking.
      metrics: ['spend', 'clicks', 'conversions', 'leads', 'purchases'],
    }),
    kind({
      kind: 'store',
      uses_metrics: false,
      names_revenue_source: true,
      metrics: [],
      metric_cap: 0,
    }),
    kind({ kind: 'lead', uses_metrics: false, metrics: [], metric_cap: 0 }),
    kind({ kind: 'traffic', uses_metrics: false, metrics: [], metric_cap: 0 }),
    kind({
      kind: 'attribution',
      uses_metrics: false,
      names_revenue_source: true,
      needs_client: true,
      is_client_safe: false,
      metrics: [],
      metric_cap: 0,
    }),
  ],
} as unknown as ReportVocabulary;

describe('presets', () => {
  it("offers the two that read one client's own records only when there is a client", () => {
    const byId = Object.fromEntries(PRESETS.map((preset) => [preset.id, preset]));

    expect(isAvailable(byId['lead_generation']!, false)).toBe(false);
    expect(isAvailable(byId['ecommerce']!, false)).toBe(false);
    expect(isAvailable(byId['lead_generation']!, true)).toBe(true);
    expect(isAvailable(byId['executive']!, false)).toBe(true);
  });

  it("never builds a section the server's vocabulary would refuse", () => {
    for (const preset of PRESETS) {
      for (const section of presetSections(preset, vocabulary)) {
        const info = vocabulary.sections.find((entry) => entry.kind === section.kind);

        expect(info).toBeDefined();
        expect(section.metrics.every((metric) => info?.metrics.includes(metric))).toBe(true);
        expect(section.metrics.length).toBeLessThanOrEqual(info?.metric_cap ?? 0);

        if (info?.needs_dimension === true) {
          expect(section.dimension).not.toBeNull();
        }
      }
    }
  });

  it('drops a ratio from a ranking instead of offering it', () => {
    const executive = PRESETS.find((preset) => preset.id === 'executive')!;
    const ranking = presetSections(executive, vocabulary).filter(
      (section) => section.kind === 'breakdown',
    );

    expect(ranking.length).toBeGreaterThan(0);
    expect(ranking.flatMap((section) => section.metrics)).not.toContain('cpa');
  });

  it("starts a store section from an invoiced source, never a model's", () => {
    const ecommerce = PRESETS.find((preset) => preset.id === 'ecommerce')!;
    const store = presetSections(ecommerce, vocabulary).find((section) => section.kind === 'store');

    expect(store?.revenue_source).toBe('salla');
  });

  it('leaves out a store section when the server offers no invoiced source', () => {
    const ecommerce = PRESETS.find((preset) => preset.id === 'ecommerce')!;
    const none = {
      ...vocabulary,
      revenue_sources: [vocabulary.revenue_sources[0]],
    } as ReportVocabulary;

    expect(presetSections(ecommerce, none).some((section) => section.kind === 'store')).toBe(false);
  });

  it('gives the weekly and monthly presets their own periods, each against the one before', () => {
    const weekly = PRESETS.find((preset) => preset.id === 'weekly')!;
    const monthly = PRESETS.find((preset) => preset.id === 'monthly')!;

    expect([weekly.window, weekly.comparison]).toEqual(['last_7_days', 'previous_period']);
    expect([monthly.window, monthly.comparison]).toEqual(['last_month', 'previous_period']);
  });

  it('gives a lead report its lead section and a funnel-capable scorecard', () => {
    const lead = PRESETS.find((preset) => preset.id === 'lead_generation')!;
    const sections = presetSections(lead, vocabulary);

    expect(sections.map((section) => section.kind)).toContain('lead');
    expect(sections.find((section) => section.kind === 'scorecard')?.metrics).toEqual(
      expect.arrayContaining(['impressions', 'clicks', 'leads']),
    );
  });

  describe('the campaign report', () => {
    const campaign = PRESETS.find((preset) => preset.id === 'campaign');

    it('reads a campaign end to end, funnel included, and needs no client to start', () => {
      expect(campaign).toBeDefined();
      expect(campaign?.needsClient).toBe(false);

      const kinds = presetSections(campaign as NonNullable<typeof campaign>, vocabulary, false).map(
        (section) => section.kind,
      );

      expect(kinds).toContain('traffic');
      expect(kinds).toContain('breakdown');
      expect(kinds).not.toContain('attribution');
    });

    it("adds attributed revenue only once a client is named, from an invoiced source — never a model's", () => {
      const sections = presetSections(campaign as NonNullable<typeof campaign>, vocabulary, true);
      const attribution = sections.find((section) => section.kind === 'attribution');

      expect(attribution?.revenue_source).toBe('salla');
    });
  });
});
