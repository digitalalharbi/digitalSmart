import type {
  ComparisonModeValue,
  CreativeViewValue,
  ReportVocabulary,
  ReportWindowValue,
  SectionDraft,
  SectionKindValue,
} from './types';

/**
 * Ready-made reports, as the choices a person would otherwise make one at a time.
 *
 * A preset is data: a period, a comparison and a list of sections. It never decides what a figure *means* — that stays
 * with the server, which publishes which metrics each shape accepts. So every section a preset builds is filtered
 * through that vocabulary: a metric a shape does not take is dropped rather than offered, and a shape the vocabulary
 * does not know is left out. A preset therefore cannot produce a report the server would refuse.
 *
 * Two presets need a client, because what they read — a store's orders, a client's recorded leads — belongs to one. They
 * are not offered for an agency-wide report.
 */
export type PresetId =
  'executive' | 'performance' | 'lead_generation' | 'ecommerce' | 'weekly' | 'monthly';

type Wanted = {
  kind: SectionKindValue;
  metrics?: string[];
  dimension?: string;
  /** For a creative section: which part of the creative picture it shows. */
  creativeView?: CreativeViewValue;
};

export type Preset = {
  id: PresetId;
  window: ReportWindowValue;
  comparison: ComparisonModeValue;
  needsClient: boolean;
  sections: Wanted[];
};

/*
 * Funnel and insight cards are not sections: they are read off the figures a report already holds. So a scorecard that
 * carries impressions, clicks and an outcome is what makes a funnel appear, and the presets say so by including them.
 */
export const PRESETS: Preset[] = [
  {
    id: 'executive',
    window: 'last_30_days',
    comparison: 'previous_period',
    needsClient: false,
    sections: [
      {
        kind: 'scorecard',
        metrics: ['spend', 'impressions', 'clicks', 'conversions', 'cpa', 'roas'],
      },
      { kind: 'trend', metrics: ['spend'] },
      { kind: 'breakdown', dimension: 'provider', metrics: ['spend'] },
      { kind: 'breakdown', dimension: 'campaign', metrics: ['spend', 'conversions'] },
    ],
  },
  {
    id: 'performance',
    window: 'last_30_days',
    comparison: 'previous_period',
    needsClient: false,
    sections: [
      {
        kind: 'scorecard',
        metrics: ['spend', 'impressions', 'clicks', 'ctr', 'conversions', 'cpa'],
      },
      { kind: 'trend', metrics: ['spend'] },
      { kind: 'trend', metrics: ['clicks'] },
      { kind: 'breakdown', dimension: 'provider', metrics: ['spend', 'clicks'] },
      { kind: 'breakdown', dimension: 'campaign', metrics: ['spend', 'clicks', 'conversions'] },
      { kind: 'creative', creativeView: 'top' },
      { kind: 'creative', creativeView: 'refresh' },
    ],
  },
  {
    id: 'lead_generation',
    window: 'last_30_days',
    comparison: 'none',
    needsClient: true,
    sections: [
      { kind: 'lead' },
      { kind: 'scorecard', metrics: ['spend', 'impressions', 'clicks', 'leads', 'cpl'] },
      { kind: 'breakdown', dimension: 'campaign', metrics: ['spend', 'leads'] },
    ],
  },
  {
    id: 'ecommerce',
    window: 'last_30_days',
    comparison: 'previous_period',
    needsClient: true,
    sections: [
      { kind: 'store' },
      {
        kind: 'scorecard',
        metrics: ['spend', 'impressions', 'clicks', 'purchases', 'revenue', 'roas'],
      },
      { kind: 'breakdown', dimension: 'campaign', metrics: ['spend', 'purchases'] },
    ],
  },
  {
    id: 'weekly',
    window: 'last_7_days',
    comparison: 'previous_period',
    needsClient: false,
    sections: [
      {
        kind: 'scorecard',
        metrics: ['spend', 'impressions', 'clicks', 'ctr', 'conversions', 'cpa'],
      },
      { kind: 'trend', metrics: ['spend'] },
      { kind: 'breakdown', dimension: 'provider', metrics: ['spend'] },
    ],
  },
  {
    id: 'monthly',
    window: 'last_month',
    comparison: 'previous_period',
    needsClient: false,
    sections: [
      {
        kind: 'scorecard',
        metrics: ['spend', 'impressions', 'clicks', 'conversions', 'cpa', 'roas'],
      },
      { kind: 'trend', metrics: ['spend'] },
      { kind: 'breakdown', dimension: 'provider', metrics: ['spend'] },
      { kind: 'breakdown', dimension: 'campaign', metrics: ['spend', 'conversions'] },
    ],
  },
];

/** The default source for a store section: an invoiced one, never a model's. Null when the server offers none. */
function invoicedSource(vocabulary: ReportVocabulary): string | null {
  return (
    (vocabulary.revenue_sources ?? []).find((source) => source.is_invoiced && !source.is_modelled)
      ?.source ?? null
  );
}

/** Whether a preset can be offered for this choice of client. */
export function isAvailable(preset: Preset, hasClient: boolean): boolean {
  return !preset.needsClient || hasClient;
}

/**
 * The sections a preset gives, restricted to what the server's vocabulary accepts.
 *
 * A section is dropped, not weakened, when its shape is unknown or the store has no invoiced revenue source to start
 * from — and a metric the shape does not take is dropped from it. A section left with no metric at all is dropped.
 */
export function presetSections(preset: Preset, vocabulary: ReportVocabulary): SectionDraft[] {
  const drafts: SectionDraft[] = [];

  for (const wanted of preset.sections) {
    const kind = vocabulary.sections.find((entry) => entry.kind === wanted.kind);

    if (kind === undefined) {
      continue;
    }

    if (wanted.kind === 'creative') {
      const view = wanted.creativeView ?? 'top';

      /* A view the server does not list is left out, as an unknown shape is. */
      if (!(vocabulary.creative_views ?? []).some((entry) => entry.view === view)) {
        continue;
      }

      drafts.push({
        kind: 'creative',
        title: null,
        metrics: [],
        dimension: null,
        row_limit: null,
        revenue_source: null,
        creative_view: view,
      });

      continue;
    }

    if (!kind.uses_metrics) {
      const revenueSource = wanted.kind === 'store' ? invoicedSource(vocabulary) : null;

      if (wanted.kind === 'store' && revenueSource === null) {
        continue;
      }

      drafts.push({
        kind: wanted.kind,
        title: null,
        metrics: [],
        dimension: null,
        row_limit: null,
        revenue_source: revenueSource,
      });

      continue;
    }

    const metrics = (wanted.metrics ?? [])
      .filter((metric) => kind.metrics.includes(metric))
      .slice(0, kind.metric_cap);

    if (metrics.length === 0) {
      continue;
    }

    drafts.push({
      kind: wanted.kind,
      title: null,
      metrics,
      dimension: kind.needs_dimension ? (wanted.dimension ?? 'provider') : null,
      row_limit: null,
      revenue_source: null,
    });
  }

  return drafts;
}
