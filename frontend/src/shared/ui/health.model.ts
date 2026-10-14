/**
 * One health vocabulary for the whole product.
 *
 * A client, a project, a campaign, a budget, a tracking source, an integration and a creative all have a
 * state somebody needs to read at a glance, and before this each module invented its own words for it —
 * "degraded", "stale", "at risk", "needs attention", "silent". A reader moving between them had to learn
 * five vocabularies to answer one question.
 *
 * These six are business-facing on purpose. The technical state underneath does not go away: a tracking
 * source is still `stale` or `silent` in the API and in the detail view. This is the sentence a person reads
 * first, and the detail is what they open when it matters.
 *
 * **`unknown` is a state, not a gap.** "We cannot tell yet" is a real answer — a campaign with four days of
 * data has no trend — and collapsing it into `healthy` is how a product comes to look confident about
 * something it has not measured.
 */
export type HealthState = 'healthy' | 'watch' | 'attention' | 'critical' | 'inactive' | 'unknown';

/** Ordering for lists that put the worst first. Inactive and unknown sink: neither is a problem to act on. */
export const HEALTH_RANK: Record<HealthState, number> = {
  critical: 0,
  attention: 1,
  watch: 2,
  unknown: 3,
  healthy: 4,
  inactive: 5,
};
