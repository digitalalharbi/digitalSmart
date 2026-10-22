import { describe, expect, it } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { creativeHero, creativeInsights, creativeRecommendations } from './creativeHighlights';
import type {
  CreativeSectionRow,
  CreativeViewValue,
  RenderedReport,
  RenderedSection,
} from './types';

const value = (metric: string, number: number | null): MetricValue => ({
  metric,
  label: metric.toUpperCase(),
  value: number,
  format: 'count',
  precision: 0,
  is_minor_units: false,
  currency: null,
  source: 'synced',
  source_label: 'Synced',
  requires_label: false,
  unavailable_reason: null,
  comparison_value: null,
  change_ratio: null,
  change_is_good: null,
  lower_is_better: false,
});

const row = (
  position: number,
  label: string,
  over: Partial<CreativeSectionRow> = {},
): CreativeSectionRow => ({
  position,
  label,
  platform: 'meta',
  platform_label: 'Meta',
  format: 'image',
  format_label: 'Image',
  media: { state: 'none', kind: 'none', url: null, origin: null },
  ads: 1,
  campaigns: [],
  campaign_count: 0,
  metrics: {
    values: { ctr: value('ctr', 2), roas: value('roas', 3), cpa: value('cpa', 40) },
    source: 'synced',
    currency: 'SAR',
    row_count: 1,
    is_empty: false,
  },
  trend: [],
  verdict: 'stable',
  verdict_label: 'Steady',
  evidence: [],
  ...over,
});

function section(
  view: CreativeViewValue,
  rows: CreativeSectionRow[],
  over: Partial<RenderedSection> = {},
): RenderedSection {
  return {
    kind: 'creative',
    title: view,
    metrics: [],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: rows.length === 0,
    caveats: [],
    currency: 'SAR',
    totals: null,
    series: [],
    rows: [],
    creative: { view, total: rows.length, mix_is_spend: true, mix: [], rows },
    ...over,
  };
}

function report(sections: RenderedSection[]): RenderedReport {
  return {
    id: 'r',
    name: 'Creatives',
    description: null,
    client_name: 'Alhambra',
    period: { from: '2026-09-01', until: '2026-09-30', days: 30, is_incomplete: false },
    comparison: {
      mode: 'none',
      label: '',
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
    sections,
  };
}

describe('creative highlights', () => {
  it("reads the hero from the gallery's totals and names the leader by ROAS where two have one", () => {
    const hero = creativeHero(
      report([
        section(
          'gallery',
          [
            row(1, 'A', {
              metrics: {
                values: { roas: value('roas', 5), ctr: value('ctr', 1) },
                source: 'synced',
                currency: 'SAR',
                row_count: 1,
                is_empty: false,
              },
            }),
            row(2, 'B'),
          ],
          {
            totals: {
              values: { spend: value('spend', 1000), ctr: value('ctr', 2.2) },
              source: 'synced',
              currency: 'SAR',
              row_count: 2,
              is_empty: false,
            },
          },
        ),
      ]),
    );

    expect(hero?.creatives).toBe(2);
    expect(hero?.totals['spend']?.value).toBe(1000);
    expect(hero?.best?.label).toBe('A');
    expect(hero?.bestBy).toBe('roas');
  });

  it('has no hero without a gallery, and no leader from one creative', () => {
    expect(creativeHero(report([section('top', [row(1, 'A')])]))).toBeNull();
    expect(creativeHero(report([section('gallery', [row(1, 'A')])]))?.best).toBeNull();
  });

  it('keeps insights to four and only where the evidence is', () => {
    const rows = [
      row(1, 'A', {
        metrics: {
          values: { ctr: value('ctr', 4), roas: value('roas', 2) },
          source: 'synced',
          currency: 'SAR',
          row_count: 1,
          is_empty: false,
        },
      }),
      row(2, 'B', {
        metrics: {
          values: { ctr: value('ctr', 1), roas: value('roas', 6) },
          source: 'synced',
          currency: 'SAR',
          row_count: 1,
          is_empty: false,
        },
      }),
      row(3, 'C', { verdict: 'fatiguing', evidence: [{ signal: 'ctr_change', value: -0.31 }] }),
    ];
    const found = creativeInsights(report([section('gallery', rows)]));

    expect(found.map((entry) => [entry.kind, entry.subject])).toEqual([
      ['best_ctr', 'A'],
      ['best_roas', 'B'],
      ['fatigue', 'C'],
    ]);
    expect(found[2]?.change).toBe(-0.31);
  });

  it('recommends a refresh only with the evidence, a scale only with a cost to stand on, three at most', () => {
    const rows = [
      row(1, 'Tired', {
        verdict: 'fatiguing',
        evidence: [
          { signal: 'ctr_change', value: -0.31 },
          { signal: 'frequency_change', value: 0.4 },
        ],
      }),
      row(2, 'Silent', { verdict: 'fatiguing', evidence: [] }),
      row(3, 'Weak', { verdict: 'weak' }),
      row(4, 'Winner', {
        metrics: {
          values: { roas: value('roas', 9), cpa: value('cpa', 12) },
          source: 'synced',
          currency: 'SAR',
          row_count: 1,
          is_empty: false,
        },
      }),
    ];
    const advice = creativeRecommendations(report([section('gallery', rows)]));

    expect(advice.map((entry) => [entry.action, entry.subject])).toEqual([
      ['refresh', 'Tired'],
      ['refresh', 'Weak'],
      ['scale', 'Winner'],
    ]);
    expect(advice[0]?.evidence.map((item) => item.label)).toEqual(['CTR', 'frequency']);
  });
});
