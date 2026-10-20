import type { LeadAggregates } from './types';

/**
 * What a lead section says, worked out from its aggregates and nothing else.
 *
 * Pure, so every threshold is a constant a reader can argue with. Nothing here estimates, extrapolates or names a
 * cause: a card either reads two figures the server sent or does not appear. In particular there is no change over
 * time — the section carries one period and a lead's outcome keeps moving after the period closes, so an
 * "improved" claim would compare a settled number with an unsettled one.
 */

/** Fewer leads than this and a rate is noise, so no source or campaign is ranked on it. */
export const MIN_SAMPLE = 5;
/** A median first contact slower than this is worth pointing out. An hour, in seconds. */
export const SLOW_RESPONSE_SECONDS = 3600;
/** A median first contact faster than this is worth saying too. Fifteen minutes, in seconds. */
export const FAST_RESPONSE_SECONDS = 900;
/** A share of leads with no recorded contact that is worth pointing out. */
export const UNANSWERED_SHARE = 0.25;
/** A route must beat the overall qualified share by this factor to be pointed at. */
export const LEADING = 1.25;

export type LeadInsightKind = 'best' | 'attention';

export type LeadInsight = {
  key: string;
  kind: LeadInsightKind;
  /** Translation key under `reports.lead.insight`. */
  text: string;
  values: Record<string, string | number>;
};

export type LeadFunnelStage = { key: string; value: number };

/**
 * Leads → reached → won, from the stages the lead table actually carries.
 *
 * **Qualified is not a step.** It is the stage a lead is *currently* in, so a lead since won passed through it
 * uncounted: placing it between reached and won would draw a funnel that narrows and then widens. It is shown as its
 * own figure and its own rate instead.
 */
export function leadFunnel(lead: LeadAggregates): LeadFunnelStage[] | null {
  if (lead.total === 0) {
    return null;
  }

  return [
    { key: 'leads', value: lead.total },
    { key: 'reached', value: lead.reached },
    { key: 'won', value: lead.won },
  ];
}

/** Qualified as a share of all the leads a route produced, 0–100, or null with too few leads to say. */
export function routeShare(row: { total: number; qualified: number }): number | null {
  return row.total >= MIN_SAMPLE ? (row.qualified / row.total) * 100 : null;
}

function overallShare(lead: LeadAggregates): number | null {
  return lead.total >= MIN_SAMPLE ? (lead.qualified / lead.total) * 100 : null;
}

/** The routes with enough leads to be ranked, strongest first. */
function rankedRoutes(lead: LeadAggregates): { label: string; share: number; total: number }[] {
  return lead.sources
    .flatMap((row) => {
      const share = routeShare(row);

      return share === null ? [] : [{ label: row.label, share, total: row.total }];
    })
    .sort((a, b) => b.share - a.share || b.total - a.total);
}

/** A median over fewer answered leads than the sample floor is noise, so it is not pointed at. */
function medianOf(lead: LeadAggregates): number | null {
  return lead.response.measured >= MIN_SAMPLE ? lead.response.median_seconds : null;
}

export function leadInsights(lead: LeadAggregates): LeadInsight[] {
  const found: LeadInsight[] = [];
  const routes = rankedRoutes(lead);
  const best = routes[0];

  // One route is not a "best": it needs a rival.
  if (best !== undefined && routes.length >= 2 && best.share > 0) {
    found.push({
      key: 'best-route',
      kind: 'best',
      text: 'bestRoute',
      values: { route: best.label, share: Math.round(best.share) },
    });
  }

  const median = medianOf(lead);

  if (median !== null && median >= SLOW_RESPONSE_SECONDS) {
    found.push({
      key: 'slow-response',
      kind: 'attention',
      text: 'slowResponse',
      values: { minutes: Math.round(median / 60) },
    });
  } else if (median !== null && median <= FAST_RESPONSE_SECONDS) {
    found.push({
      key: 'fast-response',
      kind: 'best',
      text: 'fastResponse',
      values: { minutes: Math.max(1, Math.round(median / 60)) },
    });
  }

  const answerable = lead.response.measured + lead.response.unanswered;

  if (answerable >= MIN_SAMPLE && lead.response.unanswered / answerable >= UNANSWERED_SHARE) {
    found.push({
      key: 'unanswered',
      kind: 'attention',
      text: 'unanswered',
      values: { count: lead.response.unanswered, total: answerable },
    });
  }

  return found;
}

export type LeadAdvice = {
  key: string;
  /** Translation key under `reports.lead.advice`. */
  text: string;
  /** What the pointer rests on, written from the same figures. */
  evidence: string;
  values: Record<string, string | number>;
};

/**
 * A pointer only where two figures from this report support it — never a prediction, never an instruction about
 * money.
 */
export function leadAdvice(lead: LeadAggregates): LeadAdvice[] {
  const advice: LeadAdvice[] = [];
  const overall = overallShare(lead);
  const best = rankedRoutes(lead)[0];

  if (overall !== null && best !== undefined && overall > 0 && best.share >= overall * LEADING) {
    advice.push({
      key: 'prioritise-route',
      text: 'prioritiseRoute',
      evidence: 'prioritiseRouteEvidence',
      values: { route: best.label, share: Math.round(best.share), overall: Math.round(overall) },
    });
  }

  const median = medianOf(lead);

  if (median !== null && median >= SLOW_RESPONSE_SECONDS) {
    advice.push({
      key: 'faster-response',
      text: 'fasterResponse',
      evidence: 'fasterResponseEvidence',
      values: { minutes: Math.round(median / 60), unanswered: lead.response.unanswered },
    });
  }

  return advice;
}

export type LeadCampaignCard = {
  key: string;
  label: string;
  total: number;
  qualified: number;
  share: number | null;
};

/** Named campaigns only — the unattributed row is not a campaign — ranked by qualified leads, then volume. */
export function leadCampaigns(lead: LeadAggregates): {
  top: LeadCampaignCard[];
  attention: LeadCampaignCard[];
} {
  const cards = lead.campaigns
    .filter((row) => !row.is_unattributed && row.label !== null)
    .map((row, index) => ({
      key: `${index}-${row.label ?? ''}`,
      label: row.label ?? '',
      total: row.total,
      qualified: row.qualified,
      share: routeShare(row),
    }));

  const top = [...cards].sort((a, b) => b.qualified - a.qualified || b.total - a.total).slice(0, 3);
  // Enough leads to judge and not one of them qualified (yet) — "yet", because a lead's outcome keeps moving.
  const attention = cards
    .filter((card) => card.total >= MIN_SAMPLE && card.qualified === 0)
    .sort((a, b) => b.total - a.total)
    .slice(0, 3);

  return { top, attention };
}
