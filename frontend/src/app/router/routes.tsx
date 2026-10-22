import { Suspense, type ReactElement } from 'react';
import type { RouteObject } from 'react-router';
import { AppShell } from '@/app/layout/AppShell';
import { NAV_ITEMS } from '@/app/navigation';
import { NotFoundPage } from '@/app/router/NotFoundPage';
import { RequireSession } from '@/app/router/RequireSession';
import { LoginPage } from '@/features/auth';
import { LandingPage } from '@/features/entry';
import { AdministrationPage } from '@/features/administration';
import { IntegrationsPage } from '@/features/integrations';
import { ClientsPage } from '@/features/clients';
import { ProjectsPage } from '@/features/projects';
import { NotificationsPage } from '@/features/notifications';
import { PlaceholderPage } from '@/features/placeholder';
import {
  AnalyticsPage,
  CreativesPage,
  ApprovalsPage,
  AssistantPage,
  AttributionPage,
  CreativeHubPage,
  ActionCenterPage,
  AiCenterPage,
  AutomationPage,
  AutomationAgentsPage,
  AutomationRoutinesPage,
  AutomationHistoryPage,
  AutomationChangeLogPage,
  BudgetCalculatorPage,
  BudgetControlPage,
  CampaignControlPage,
  ClientPortfolioPage,
  PortfolioPage,
  ProjectSetupPage,
  ProjectWorkspacePage,
  AdDetailPage,
  AdGroupDetailPage,
  AdGroupsPage,
  AdsPage,
  CampaignDetailPage,
  CampaignBuilderPage,
  CampaignsPage,
  DashboardsPage,
  LeadCentrePage,
  MediaPlannerPage,
  OperationsPage,
  MyPortalPage,
  PortalPage,
  PresentationsPage,
  ReportManagePage,
  ReportsPage,
  RequestsPage,
  SharedReportPage,
  ApprovalLinkPage,
  StoreAnalyticsPage,
  StoresRollupPage,
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
  '/ad-groups': deferred(<AdGroupsPage />),
  '/ads': deferred(<AdsPage />),
  '/portfolio': deferred(<PortfolioPage />),
  '/budget-control': deferred(<BudgetControlPage />),
  '/dashboards': deferred(<DashboardsPage />),
  '/media-planner': deferred(<MediaPlannerPage />),
  '/budget-calculator': deferred(<BudgetCalculatorPage />),
  '/tracking': deferred(<TrackingCenterPage />),
  '/leads': deferred(<LeadCentrePage />),
  '/attribution': deferred(<AttributionPage />),
  '/creative-hub': deferred(<CreativeHubPage />),
  '/creatives': deferred(<CreativesPage />),
  '/today': deferred(<ActionCenterPage />),
  '/ai-center': deferred(<AiCenterPage />),
  '/automation': deferred(<AutomationPage />),
  '/automation/agents': deferred(<AutomationAgentsPage />),
  '/automation/routines': deferred(<AutomationRoutinesPage />),
  '/automation/history': deferred(<AutomationHistoryPage />),
  '/automation/changes': deferred(<AutomationChangeLogPage />),
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
  '/commerce/stores': deferred(<StoresRollupPage />),
};

const featureRoutes: RouteObject[] = NAV_ITEMS.filter((item) => item.path !== '/').map((item) => ({
  path: item.path.replace(/^\//, ''),
  element: IMPLEMENTED[item.path] ?? (
    <PlaceholderPage titleKey={item.labelKey} phase={item.phase} />
  ),
}));

export const routes: RouteObject[] = [
  { path: '/login', element: <LoginPage /> },
  { path: '/welcome', element: <LandingPage /> },
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
  /* A one-time approval link from WhatsApp or email: no session, the link is the credential, and it decides only on a press. */
  { path: '/approve/:token', element: deferred(<ApprovalLinkPage />) },
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
      { path: 'campaigns/new', element: deferred(<CampaignBuilderPage />) },
      { path: 'campaigns/:campaignId', element: deferred(<CampaignDetailPage />) },
      { path: 'reports/:reportId/manage', element: deferred(<ReportManagePage />) },
      { path: 'ad-groups/:adGroupId', element: deferred(<AdGroupDetailPage />) },
      { path: 'ads/:adId', element: deferred(<AdDetailPage />) },
      { path: 'clients/:clientId', element: deferred(<ClientPortfolioPage />) },
      { path: 'projects/:projectId', element: deferred(<ProjectWorkspacePage />) },
      { path: 'projects/:projectId/setup', element: deferred(<ProjectSetupPage />) },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];
