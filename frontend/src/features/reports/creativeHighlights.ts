import type { MetricValue } from '@/shared/metrics';
import type {
  CreativeSectionData,
  CreativeSectionRow,
  RenderedReport,
  RenderedSection,
} from './types';

/**
 * What the creative sections say when read together — the hero's figures, at most four insights, at most three
 * recommendations. Nothing is computed: every figure is one the server published on a row or in the gallery's
 * totals, and a card exists only where the evidence for it does.
 */
const MAX_INSIGHTS = 4;
const MAX_RECOMMENDATIONS = 3;

export type CreativeHeroFigures = {
  creatives: number | null;
  totals: Record<string, MetricValue>;
  best: CreativeSectionRow | null;
  /** Why `best` is best: the metric it led on. */
  bestBy: 'roas' | 'ctr' | null;
};

export type CreativeInsight = {
  key: string;
  kind: 'best_ctr' | 'best_roas' | 'fatigue' | 'best_platform';
  subject: string;
  /** The figure, formatted by the caller from `value`/`format`. */
  value: MetricValue | null;
  /** A change ratio where the insight is about movement. */
  change: number | null;
  media: CreativeSectionRow['media'] | null;
};

export type CreativeRecommendation = {
  key: string;
  action: 'refresh' | 'scale';
  subject: string;
  evidence: Array<{ label: string; value: MetricValue | null; change: number | null }>;
  media: CreativeSectionRow['media'] | null;
};

function creativeSections(
  report: RenderedReport,
): Array<RenderedSection & { creative: CreativeSectionData }> {
  return report.sections.filter(
    (section): section is RenderedSection & { creative: CreativeSectionData } =>
      section.kind === 'creative' && section.creative != null && !section.is_empty,
  );
}

/** Every creative the report shows, once each, by position within its section. */
function allRows(report: RenderedReport): CreativeSectionRow[] {
  const seen = new Set<string>();
  const out: CreativeSectionRow[] = [];

  for (const section of creativeSections(report)) {
    for (const row of section.creative.rows) {
      const key = `${row.label}|${row.platform}|${row.format}`;

      if (!seen.has(key)) {
        seen.add(key);
        out.push(row);
      }
    }
  }

  return out;
}

const metric = (row: CreativeSectionRow, key: string): number | null =>
  row.metrics.values[key]?.value ?? null;

function leader(rows: CreativeSectionRow[], key: 'roas' | 'ctr'): CreativeSectionRow | null {
  const withValue = rows.filter((row) => (metric(row, key) ?? 0) > 0);

  if (withValue.length < 2) {
    return null;
  }

  return withValue.reduce((best, row) =>
    (metric(row, key) ?? 0) > (metric(best, key) ?? 0) ? row : best,
  );
}

/** The gallery section carries the scope's totals; it is the hero's source. Absent, there is no hero. */
export function creativeHero(report: RenderedReport): CreativeHeroFigures | null {
  const gallery = creativeSections(report).find((section) => section.creative.view === 'gallery');

  if (gallery === undefined) {
    return null;
  }

  const rows = allRows(report);
  const byRoas = leader(rows, 'roas');
  const best = byRoas ?? leader(rows, 'ctr');

  return {
    creatives: gallery.creative.total,
    totals: gallery.totals?.values ?? {},
    best,
    bestBy: best === null ? null : byRoas !== null ? 'roas' : 'ctr',
  };
}

export function creativeInsights(report: RenderedReport): CreativeInsight[] {
  const rows = allRows(report);
  const out: CreativeInsight[] = [];

  const ctrLeader = leader(rows, 'ctr');
  if (ctrLeader !== null) {
    out.push({
      key: 'best_ctr',
      kind: 'best_ctr',
      subject: ctrLeader.label,
      value: ctrLeader.metrics.values['ctr'] ?? null,
      change: null,
      media: ctrLeader.media,
    });
  }

  const roasLeader = leader(rows, 'roas');
  if (roasLeader !== null) {
    out.push({
      key: 'best_roas',
      kind: 'best_roas',
      subject: roasLeader.label,
      value: roasLeader.metrics.values['roas'] ?? null,
      change: null,
      media: roasLeader.media,
    });
  }

  /* Fatigue is the server's verdict with its own evidence; the card shows the click-through change it rests on. */
  const fatiguing = rows.find((row) => row.verdict === 'fatiguing');
  const ctrChange = fatiguing?.evidence.find((entry) => entry.signal === 'ctr_change');
  if (fatiguing !== undefined && typeof ctrChange?.value === 'number') {
    out.push({
      key: 'fatigue',
      kind: 'fatigue',
      subject: fatiguing.label,
      value: fatiguing.metrics.values['ctr'] ?? null,
      change: ctrChange.value,
      media: fatiguing.media,
    });
  }

  const mix =
    creativeSections(report).find((section) => section.creative.view === 'platform_mix')?.creative
      .platform_mix ?? [];
  const bestPlatform =
    mix.length >= 2 ? [...mix].sort((a, b) => b.results - a.results)[0] : undefined;
  if (bestPlatform !== undefined && bestPlatform.results > 0) {
    out.push({
      key: 'best_platform',
      kind: 'best_platform',
      subject: bestPlatform.label,
      value: null,
      change: null,
      media: null,
    });
  }

  return out.slice(0, MAX_INSIGHTS);
}

export function creativeRecommendations(report: RenderedReport): CreativeRecommendation[] {
  const rows = allRows(report);
  const out: CreativeRecommendation[] = [];

  for (const row of rows) {
    if (out.length >= MAX_RECOMMENDATIONS) {
      break;
    }

    if (row.verdict === 'fatiguing' || row.verdict === 'weak') {
      const ctr = row.evidence.find((entry) => entry.signal === 'ctr_change');
      const frequency = row.evidence.find((entry) => entry.signal === 'frequency_change');

      /* A refresh needs the evidence beside it; a verdict with none says nothing a client can act on. */
      if (row.verdict === 'weak' || typeof ctr?.value === 'number') {
        out.push({
          key: `refresh-${String(row.position)}-${row.label}`,
          action: 'refresh',
          subject: row.label,
          evidence: [
            {
              label: 'CTR',
              value: row.metrics.values['ctr'] ?? null,
              change: typeof ctr?.value === 'number' ? ctr.value : null,
            },
            ...(typeof frequency?.value === 'number'
              ? [
                  {
                    label: 'frequency',
                    value: row.metrics.values['frequency'] ?? null,
                    change: frequency.value,
                  },
                ]
              : []),
          ],
          media: row.media,
        });
      }
    }
  }

  /* Scale the ROAS leader, when it also has a cost per result to stand on. */
  const roasLeader = leader(rows, 'roas');
  if (
    roasLeader !== null &&
    (metric(roasLeader, 'cpa') ?? null) !== null &&
    out.length < MAX_RECOMMENDATIONS &&
    !out.some((entry) => entry.subject === roasLeader.label)
  ) {
    out.push({
      key: `scale-${roasLeader.label}`,
      action: 'scale',
      subject: roasLeader.label,
      evidence: [
        { label: 'ROAS', value: roasLeader.metrics.values['roas'] ?? null, change: null },
        { label: 'CPA', value: roasLeader.metrics.values['cpa'] ?? null, change: null },
      ],
      media: roasLeader.media,
    });
  }

  return out.slice(0, MAX_RECOMMENDATIONS);
}
