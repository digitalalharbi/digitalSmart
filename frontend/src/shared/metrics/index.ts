/**
 * The metric vocabulary, shared rather than owned by one feature.
 *
 * It lives here because `shared/ui` needs it: a metric card and a source badge are
 * generic components, and a shared component that imports from a feature inverts
 * the dependency — which the lint rule rightly refuses. Analytics, campaigns,
 * reports and dashboards all speak this vocabulary, so none of them owns it.
 */
export * from './types';
export { formatMetric, formatChange, MISSING } from './format';
export { trendOption, breakdownOption, efficiencyOption, trendMetricsFor } from './charts';
