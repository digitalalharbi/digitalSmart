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
import { OverviewPage } from '@/features/overview';
import { ProjectsPage } from '@/features/projects';
import { NotificationsPage } from '@/features/notifications';
import { PlaceholderPage } from '@/features/placeholder';
import {
  AnalyticsPage,
  ApprovalsPage,
  AttributionPage,
  CreativeHubPage,
  BudgetCalculatorPage,
  CampaignsPage,
  DashboardsPage,
  LeadCentrePage,
  MediaPlannerPage,
  OperationsPage,
  ReportsPage,
  RequestsPage,
  SharedReportPage,
  StoreAnalyticsPage,
  TrackingCenterPage,
} from '@/app/router/lazyPages';
import { SurfaceFallback } from '@/app/router/SurfaceFallback';

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
  '/campaigns': deferred(<CampaignsPage />),
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
      { index: true, element: <OverviewPage /> },
      ...featureRoutes,
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];
