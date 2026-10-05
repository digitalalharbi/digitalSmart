/**
 * The analytics feature's reusable parts.
 *
 * The page components are deliberately **not** exported here. They import the
 * charting engine, which is larger than the rest of the application put together,
 * and anything importing this barrel would pull it into the initial bundle — which
 * is exactly what happened when the overview screen started showing a scorecard.
 * The router imports the pages directly, lazily.
 */
export { FilterBar } from './FilterBar';
export { BreakdownTable } from './BreakdownTable';
export { useAnalytics, useMetricCatalogue } from './useAnalytics';
export { fetchAnalytics, fetchCatalogue } from './api';
