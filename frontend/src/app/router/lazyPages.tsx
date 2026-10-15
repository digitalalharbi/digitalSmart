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

export const StoresRollupPage = lazy(async () => ({
  default: (await import('@/features/commerce/StoresRollupPage')).StoresRollupPage,
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

export const CreativeHubPage = lazy(async () => ({
  default: (await import('@/features/creative/CreativeHubPage')).CreativeHubPage,
}));

export const OperationsPage = lazy(async () => ({
  default: (await import('@/features/operations/OperationsPage')).OperationsPage,
}));

export const ApprovalsPage = lazy(async () => ({
  default: (await import('@/features/approvals/ApprovalsPage')).ApprovalsPage,
}));

export const RequestsPage = lazy(async () => ({
  default: (await import('@/features/requests/RequestsPage')).RequestsPage,
}));

export const ReportsPage = lazy(async () => ({
  default: (await import('@/features/reports/ReportsPage')).ReportsPage,
}));

/*
 * The shared-link page, which is the one surface a visitor with no account ever loads. Split for the opposite
 * reason to the rest: not to keep the charting engine out of the first paint, but to keep the signed-in
 * application out of a page that a client opens from an email.
 */
export const SharedReportPage = lazy(async () => ({
  default: (await import('@/features/reports/SharedReportPage')).SharedReportPage,
}));

/*
 * The agency's portal configuration, and the client's own portal. Both split: the configuration surface is only
 * ever opened by somebody deciding a disclosure, and the client shell pulls the chart engine and the revenue and
 * tracking surfaces with it, none of which belongs in an agency member's first paint.
 */
export const PortalPage = lazy(async () => ({
  default: (await import('@/features/portal/PortalPage')).PortalPage,
}));

export const MyPortalPage = lazy(async () => ({
  default: (await import('@/features/portal/MyPortalPage')).MyPortalPage,
}));

/*
 * The assistant. Split because it pulls the finding and evidence rendering with it, and because an agency member
 * who never opens it should not pay for it on every other screen.
 */
export const AssistantPage = lazy(async () => ({
  default: (await import('@/features/assistant/AssistantPage')).AssistantPage,
}));

/*
 * Presentations. Split because a deck carries the chart engine and the slide renderer, and because this is the
 * one surface somebody opens to print rather than to work.
 */
export const PresentationsPage = lazy(async () => ({
  default: (await import('@/features/presentations/PresentationsPage')).PresentationsPage,
}));

export const CampaignControlPage = lazy(async () => ({
  default: (await import('@/features/campaign-control/CampaignControlPage')).CampaignControlPage,
}));

export const PortfolioPage = lazy(async () => ({
  default: (await import('@/features/portfolio/PortfolioPage')).PortfolioPage,
}));

export const ClientPortfolioPage = lazy(async () => ({
  default: (await import('@/features/portfolio/ClientPortfolioPage')).ClientPortfolioPage,
}));

export const BudgetControlPage = lazy(async () => ({
  default: (await import('@/features/budget-control/BudgetControlPage')).BudgetControlPage,
}));

export const ProjectSetupPage = lazy(async () => ({
  default: (await import('@/features/projects/ProjectSetupPage')).ProjectSetupPage,
}));
