import { Suspense, type ReactElement } from 'react';
import type { RouteObject } from 'react-router';
import { AppShell } from '@/app/layout/AppShell';
import { NAV_ITEMS } from '@/app/navigation';
import { NotFoundPage } from '@/app/router/NotFoundPage';
import { RequireSession } from '@/app/router/RequireSession';
import { LoginPage } from '@/features/auth';
import { AdministrationPage } from '@/features/administration';
import { IntegrationsPage } from '@/features/integrations';
import { ClientsPage } from '@/features/clients';
import { ProjectsPage } from '@/features/projects';
import { NotificationsPage } from '@/features/notifications';
import { PlaceholderPage } from '@/features/placeholder';
import {
  AnalyticsPage,
  ApprovalsPage,
  AssistantPage,
  AttributionPage,
  CreativeHubPage,
  BudgetCalculatorPage,
  BudgetControlPage,
  CampaignControlPage,
  ClientPortfolioPage,
  PortfolioPage,
  CampaignsPage,
  DashboardsPage,
  LeadCentrePage,
  MediaPlannerPage,
  OperationsPage,
  MyPortalPage,
  PortalPage,
  PresentationsPage,
  ReportsPage,
  RequestsPage,
  SharedReportPage,
  StoreAnalyticsPage,
  TrackingCenterPage,
} from '@/app/router/lazyPages';
import { SurfaceFallback } from '@/app/router/SurfaceFallback';
import { PortalLanding, PortalRedirectGuard } from '@/app/router/portalGuards';

/** Wraps a lazily loaded surface so its chunk can arrive without a blank page. */
function deferred(element: ReactElement): ReactElement {
  return <Suspense fallback={<SurfaceFallback />}>{element}</Suspense>;
}

/** Surfaces whose real page has landed. The rest fall back to an honest placeholder. */
const IMPLEMENTED: Record<string, ReactElement> = {
  '/clients': <ClientsPage />,
  '/projects': <ProjectsPage />,
  '/administration': <AdministrationPage />,
  '/integrations': <IntegrationsPage />,
  '/analytics': deferred(<AnalyticsPage />),
  /* The control center is the daily surface; the metric drill-down it grew out of lives one level down. */
  '/campaigns': deferred(<CampaignControlPage />),
  '/portfolio': deferred(<PortfolioPage />),
  '/budget-control': deferred(<BudgetControlPage />),
  '/dashboards': deferred(<DashboardsPage />),
  '/media-planner': deferred(<MediaPlannerPage />),
  '/budget-calculator': deferred(<BudgetCalculatorPage />),
  '/tracking': deferred(<TrackingCenterPage />),
  '/leads': deferred(<LeadCentrePage />),
  '/attribution': deferred(<AttributionPage />),
  '/creative-hub': deferred(<CreativeHubPage />),
  '/tasks': deferred(<OperationsPage />),
  '/approvals': deferred(<ApprovalsPage />),
  '/client-requests': deferred(<RequestsPage />),
  '/reports': deferred(<ReportsPage />),
  '/client-portal': deferred(<PortalPage />),
  '/ai-assistant': deferred(<AssistantPage />),
  '/presentations': deferred(<PresentationsPage />),
  /*
   * Not deferred. The bell already loads this feature on every screen, so a second chunk for the page would be
   * a download that buys nothing.
   */
  '/notifications': <NotificationsPage />,
  '/commerce': deferred(<StoreAnalyticsPage />),
};

const featureRoutes: RouteObject[] = NAV_ITEMS.filter((item) => item.path !== '/').map((item) => ({
  path: item.path.replace(/^\//, ''),
  element: IMPLEMENTED[item.path] ?? (
    <PlaceholderPage titleKey={item.labelKey} phase={item.phase} />
  ),
}));

export const routes: RouteObject[] = [
  { path: '/login', element: <LoginPage /> },
  /*
   * The client's own portal, in its own shell rather than the agency's — no sidebar of agency surfaces, no
   * notification bell. It is behind the session because a client signs in; what it shows is decided by the
   * portal's module grants rather than by this route.
   */
  {
    path: '/portal',
    element: (
      <RequireSession>
        <PortalRedirectGuard>
          <MyPortalPage />
        </PortalRedirectGuard>
      </RequireSession>
    ),
  },
  /*
   * Outside the shell and outside `RequireSession`, which is the whole point of it: a client opens this from an
   * email and has no account to be sent to a sign-in screen for. The token is in the path and nothing else is.
   */
  { path: '/shared-reports/:token', element: deferred(<SharedReportPage />) },
  {
    path: '/',
    element: (
      <RequireSession>
        <AppShell />
      </RequireSession>
    ),
    children: [
      { index: true, element: <PortalLanding /> },
      ...featureRoutes,
      { path: 'campaigns/explore', element: deferred(<CampaignsPage />) },
      { path: 'clients/:clientId', element: deferred(<ClientPortfolioPage />) },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];
