import { lazy } from 'react';

/*
 * The chart-heavy surfaces, loaded on first visit.
 *
 * The charting engine is larger than the rest of this application put together, so
 * loading it on the sign-in screen would make the first paint of every session pay
 * for a surface most visits never open. These import the page modules directly
 * rather than their feature barrels: a barrel that re-exported a page would pull
 * the engine back into the initial bundle through anything that imported a hook
 * from it, which is exactly what happened when the overview screen first grew a
 * scorecard.
 *
 * Its own file because this module exports components and nothing else, which is
 * what React's fast-refresh boundary needs.
 */
export const AnalyticsPage = lazy(async () => ({
  default: (await import('@/features/analytics/AnalyticsPage')).AnalyticsPage,
}));

export const CampaignsPage = lazy(async () => ({
  default: (await import('@/features/campaigns/CampaignsPage')).CampaignsPage,
}));

export const DashboardsPage = lazy(async () => ({
  default: (await import('@/features/dashboards/DashboardsPage')).DashboardsPage,
}));

export const MediaPlannerPage = lazy(async () => ({
  default: (await import('@/features/budgets/MediaPlannerPage')).MediaPlannerPage,
}));

/*
 * The calculator draws no charts, but it shares the budget feature's modules with the
 * planner — so splitting it the same way keeps one chunk for both rather than
 * duplicating the shared code into the initial bundle.
 */
export const BudgetCalculatorPage = lazy(async () => ({
  default: (await import('@/features/budgets/BudgetCalculatorPage')).BudgetCalculatorPage,
}));

export const TrackingCenterPage = lazy(async () => ({
  default: (await import('@/features/tracking/TrackingCenterPage')).TrackingCenterPage,
}));

export const StoreAnalyticsPage = lazy(async () => ({
  default: (await import('@/features/commerce/StoreAnalyticsPage')).StoreAnalyticsPage,
}));

export const LeadCentrePage = lazy(async () => ({
  default: (await import('@/features/leads/LeadCentrePage')).LeadCentrePage,
}));

export const AttributionPage = lazy(async () => ({
  default: (await import('@/features/attribution/AttributionPage')).AttributionPage,
}));
