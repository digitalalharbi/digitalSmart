import type { RenderedReport, RenderedSection } from '@/features/reports';
import type { AnalyticsPayload, BreakdownRowPayload, MetricSetPayload } from '@/shared/metrics';

/**
 * The analytics the page already holds, in the shape the report system reads.
 *
 * Insights, the funnel, the campaign cards and the pointers are written once, over a report's sections, and the
 * client's live report and this page must say the same thing about the same figures — so this page does not grow a
 * second copy of that logic. It hands the same functions the same figures in the same shape. Nothing is computed here: a
 * value, its comparison and its change are the server's own, and a change the server withheld stays withheld.
 *
 * Only what the report reader needs is filled in; the rest of a report's envelope is inert for this use.
 */
function metricSet(payload: MetricSetPayload): NonNullable<RenderedSection['totals']> {
  return {
    values: payload.values,
    source: payload.source,
    currency: payload.currency,
    row_count: payload.row_count,
    is_empty: payload.row_count === 0,
  };
}

function section(
  kind: RenderedSection['kind'],
  title: string,
  payload: AnalyticsPayload,
  rows: BreakdownRowPayload[],
  dimension: string | null,
): RenderedSection {
  return {
    kind,
    title,
    metrics: payload.metrics,
    dimension,
    dimension_label: dimension,
    source: payload.source,
    source_label: payload.source_label,
    requires_label: payload.requires_label,
    is_empty: payload.is_empty,
    caveats: [],
    currency: payload.summary.currency,
    totals: kind === 'scorecard' ? metricSet(payload.summary) : null,
    series:
      kind === 'trend' ? payload.series.map((p) => ({ date: p.date, metrics: metricSet(p) })) : [],
    rows: rows.map((row) => ({
      key: row.key,
      label: row.label,
      status: row.status,
      metrics: metricSet(row),
    })),
  };
}

export function analyticsAsReport(
  main: AnalyticsPayload,
  platforms: AnalyticsPayload | undefined,
  campaigns: AnalyticsPayload | undefined,
): RenderedReport {
  const compared = main.compare_to !== null;

  return {
    id: 'analytics',
    name: 'Analytics',
    description: null,
    client_name: null,
    period: {
      from: main.range.start,
      until: main.range.end,
      days: main.range.days,
      is_incomplete: false,
    },
    comparison: {
      mode: compared ? 'previous_period' : 'none',
      label: '',
      from: main.compare_to?.start ?? null,
      until: main.compare_to?.end ?? null,
      was_requested: compared,
      is_comparable: compared,
      reason: null,
      code: null,
    },
    audience: 'internal',
    mode: 'live',
    mode_label: '',
    source: main.source,
    source_label: main.source_label,
    requires_label: main.requires_label,
    is_empty: main.is_empty,
    internal_note: null,
    rendered_at: '',
    sections: [
      section('scorecard', 'scorecard', main, [], null),
      ...(platforms === undefined
        ? []
        : [section('breakdown', 'platforms', platforms, platforms.breakdown, 'provider')]),
      ...(campaigns === undefined
        ? []
        : [section('breakdown', 'campaigns', campaigns, campaigns.breakdown, 'campaign')]),
    ],
  };
}
